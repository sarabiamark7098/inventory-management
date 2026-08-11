"use client";

import { BehaviorSubject } from "rxjs";

import type {
  BorrowingTransaction,
  Category,
  ConnectionState,
  Equipment,
  SyncPullResponse,
  SyncPushRequest,
  SyncPushResponse,
  SyncStatusSnapshot,
} from "@/lib/domain/types";
import { uuid } from "@/lib/utils/id";
import { getDatabase } from "./database";
import { notifyOverdue, sweepOverdue } from "./overdue";
import {
  acknowledge,
  claimBatch,
  countByStatus,
  markFailed,
  pendingDocIds,
  releaseStale,
  requeue,
} from "./outbox";

const CLIENT_ID_KEY = "ict.sync.clientId";
const LAST_SYNCED_KEY = "ict.sync.lastSyncedAt";
const POLL_INTERVAL_MS = 30_000;
const PUSH_BATCH_SIZE = 100;

export function getClientId(): string {
  if (typeof window === "undefined") return "server";
  let id = window.localStorage.getItem(CLIENT_ID_KEY);
  if (!id) {
    id = uuid();
    window.localStorage.setItem(CLIENT_ID_KEY, id);
  }
  return id;
}

function getLastSyncedAt(): string | null {
  if (typeof window === "undefined") return null;
  return window.localStorage.getItem(LAST_SYNCED_KEY);
}

function setLastSyncedAt(value: string): void {
  window.localStorage.setItem(LAST_SYNCED_KEY, value);
}

/* ------------------------------------------------------------------ */
/* Observable status (US-4.4)                                          */
/* ------------------------------------------------------------------ */

const initialStatus: SyncStatusSnapshot = {
  connection: "OFFLINE",
  pending: 0,
  failed: 0,
  lastSyncedAt: null,
  lastError: null,
};

export const syncStatus$ = new BehaviorSubject<SyncStatusSnapshot>(
  initialStatus,
);

function patchStatus(patch: Partial<SyncStatusSnapshot>): void {
  syncStatus$.next({ ...syncStatus$.getValue(), ...patch });
}

function browserOnline(): boolean {
  return typeof navigator === "undefined" ? false : navigator.onLine;
}

async function refreshCounters(): Promise<void> {
  const [pending, failed] = await Promise.all([
    countByStatus(["PENDING", "IN_FLIGHT"]),
    countByStatus(["FAILED"]),
  ]);
  patchStatus({ pending, failed });
}

/* ------------------------------------------------------------------ */
/* Local reconciliation                                                */
/* ------------------------------------------------------------------ */

type AnyEntity = Category | Equipment | BorrowingTransaction;

/**
 * Writes a server document into the local store.
 *
 * `force` is used for push responses (the server just adjudicated this exact
 * document). During a pull we skip documents that still have queued local
 * edits — that is the "local override" half of US-4.3.
 */
async function applyServerDocument(
  table: "categories" | "equipment" | "borrowings",
  serverDoc: AnyEntity,
  force: boolean,
): Promise<void> {
  const db = await getDatabase();
  const collection = db[table];
  const local = await collection.findOne(serverDoc.id).exec();

  if (!local) {
    await collection.insert(serverDoc as never);
    return;
  }

  if (!force) {
    // Last-Write-Wins on UTC timestamps.
    const localTs = new Date(local.get("updatedAt") as string).getTime();
    const serverTs = new Date(serverDoc.updatedAt).getTime();
    if (serverTs <= localTs) return;
  }

  await local.incrementalPatch(serverDoc as never);
}

async function applyPull(payload: SyncPullResponse): Promise<void> {
  const tables = [
    ["categories", payload.categories],
    ["equipment", payload.equipment],
    ["borrowings", payload.borrowings],
  ] as const;

  for (const [table, docs] of tables) {
    if (docs.length === 0) continue;
    const locallyDirty = await pendingDocIds(table);
    for (const doc of docs) {
      // A server-flagged conflict always wins, even over unsynced local edits,
      // so the operator sees the contested record immediately.
      const isConflict =
        table === "borrowings" &&
        (doc as BorrowingTransaction).status === "CONFLICT_PENDING_REVIEW";
      if (locallyDirty.has(doc.id) && !isConflict) continue;
      await applyServerDocument(table, doc, isConflict);
    }
  }
}

/* ------------------------------------------------------------------ */
/* Push / pull phases                                                  */
/* ------------------------------------------------------------------ */

async function pushPhase(): Promise<{ conflicts: number }> {
  await releaseStale();
  const batch = await claimBatch(PUSH_BATCH_SIZE);
  if (batch.length === 0) return { conflicts: 0 };

  const body: SyncPushRequest = {
    clientId: getClientId(),
    changes: batch.map((entry) => ({
      id: entry.id,
      table: entry.table,
      action: entry.action,
      docId: entry.docId,
      payload: entry.payload,
      clientTimestamp: entry.clientTimestamp,
    })),
  };

  let response: Response;
  try {
    response = await fetch("/api/sync/push", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
  } catch (err) {
    // Offline or DNS/socket failure: retry these untouched next cycle.
    await requeue(
      batch.map((e) => e.id),
      err instanceof Error ? err.message : "Network error during push",
    );
    throw err;
  }

  if (!response.ok) {
    const text = await response.text().catch(() => response.statusText);
    const message = `Push failed (${response.status}): ${text}`;
    const ids = batch.map((e) => e.id);
    // 5xx is the server's problem, not the payload's — keep retrying. 4xx means
    // the server will never accept this batch, so it needs operator attention.
    if (response.status >= 500) {
      await requeue(ids, message);
    } else {
      await markFailed(ids, message);
    }
    throw new Error(message);
  }

  const result = (await response.json()) as SyncPushResponse;
  const settled: string[] = [];
  const rejected: string[] = [];
  let conflicts = 0;

  for (const item of result.results) {
    if (item.status === "REJECTED") {
      rejected.push(item.changeId);
    } else {
      settled.push(item.changeId);
    }
    if (item.status === "CONFLICT") conflicts++;
    if (item.document) {
      await applyServerDocument(item.table, item.document, true);
    }
  }

  await acknowledge(settled);
  if (rejected.length > 0) {
    const reason =
      result.results.find((r) => r.status === "REJECTED")?.message ??
      "Server rejected the change";
    await markFailed(rejected, reason);
  }

  return { conflicts };
}

async function pullPhase(): Promise<string> {
  const since = getLastSyncedAt();
  const url = since
    ? `/api/sync/pull?last_synced_at=${encodeURIComponent(since)}`
    : "/api/sync/pull";

  const response = await fetch(url, { headers: { Accept: "application/json" } });
  if (!response.ok) {
    const text = await response.text().catch(() => response.statusText);
    throw new Error(`Pull failed (${response.status}): ${text}`);
  }

  const payload = (await response.json()) as SyncPullResponse;
  await applyPull(payload);
  setLastSyncedAt(payload.serverTime);
  return payload.serverTime;
}

let inFlight: Promise<void> | null = null;

/** Runs one full push→pull cycle. Concurrent calls share the same run. */
export function synchronize(): Promise<void> {
  if (inFlight) return inFlight;

  inFlight = (async () => {
    if (!browserOnline()) {
      patchStatus({ connection: "OFFLINE" });
      await refreshCounters();
      return;
    }

    patchStatus({ connection: "SYNCING", lastError: null });
    try {
      // Promote past-due loans before pushing so the server sees them too.
      await sweepOverdue().then(notifyOverdue);
      await pushPhase();
      const serverTime = await pullPhase();
      await refreshCounters();
      patchStatus({
        connection: "ONLINE",
        lastSyncedAt: serverTime,
        lastError: null,
      });
    } catch (err) {
      await refreshCounters();
      const message = err instanceof Error ? err.message : String(err);
      patchStatus({
        connection: browserOnline() ? "ERROR" : "OFFLINE",
        lastError: message,
      });
    }
  })().finally(() => {
    inFlight = null;
  });

  return inFlight;
}

/** Clears FAILED entries back to PENDING so the next cycle retries them. */
export async function retryFailed(): Promise<void> {
  const db = await getDatabase();
  const docs = await db.sync_queue
    .find({ selector: { status: "FAILED" } })
    .exec();
  await Promise.all(
    docs.map((doc) =>
      doc.incrementalPatch({ status: "PENDING" as const, lastError: null }),
    ),
  );
  await refreshCounters();
  await synchronize();
}

/* ------------------------------------------------------------------ */
/* Lifecycle                                                           */
/* ------------------------------------------------------------------ */

let started = false;

/**
 * Starts the background engine: syncs on load, on regained connectivity, and on
 * a slow poll. Only the elected leader tab drives the loop (US-4.2).
 */
export function startSyncEngine(): () => void {
  if (typeof window === "undefined" || started) return () => {};
  started = true;

  patchStatus({
    connection: browserOnline() ? "ONLINE" : "OFFLINE",
    lastSyncedAt: getLastSyncedAt(),
  });

  const onOnline = () => {
    patchStatus({ connection: "ONLINE" });
    void synchronize();
  };
  const onOffline = () => patchStatus({ connection: "OFFLINE" });
  const onVisible = () => {
    if (document.visibilityState === "visible") void synchronize();
  };

  window.addEventListener("online", onOnline);
  window.addEventListener("offline", onOffline);
  document.addEventListener("visibilitychange", onVisible);

  let interval: ReturnType<typeof setInterval> | undefined;
  let queueSub: { unsubscribe: () => void } | undefined;

  void (async () => {
    const db = await getDatabase();
    await refreshCounters();

    // Keep the pending badge live in every tab, even non-leader ones.
    queueSub = db.sync_queue.$.subscribe(() => {
      void refreshCounters();
    });

    await db.waitForLeadership();
    void synchronize();
    interval = setInterval(() => void synchronize(), POLL_INTERVAL_MS);
  })();

  return () => {
    started = false;
    window.removeEventListener("online", onOnline);
    window.removeEventListener("offline", onOffline);
    document.removeEventListener("visibilitychange", onVisible);
    if (interval) clearInterval(interval);
    queueSub?.unsubscribe();
  };
}

export function currentConnection(): ConnectionState {
  return syncStatus$.getValue().connection;
}
