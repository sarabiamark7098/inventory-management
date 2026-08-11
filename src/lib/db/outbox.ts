"use client";

import type {
  EntityType,
  SyncOperation,
  SyncQueueEntry,
  SyncQueueStatus,
} from "@/lib/domain/types";
import { nowIso, uuid } from "@/lib/utils/id";
import { getDatabase } from "./database";

/**
 * Outbox pattern.
 *
 * Every local mutation writes the document AND appends an entry here inside the
 * same logical step. The sync engine drains this queue in `clientTimestamp`
 * order, so the server replays the user's edits in the order they happened.
 */

export interface EnqueueInput {
  table: EntityType;
  action: SyncOperation;
  docId: string;
  payload: Record<string, unknown>;
}

export async function enqueue(input: EnqueueInput): Promise<SyncQueueEntry> {
  const db = await getDatabase();
  const entry: SyncQueueEntry = {
    id: uuid(),
    table: input.table,
    action: input.action,
    docId: input.docId,
    payload: input.payload,
    clientTimestamp: nowIso(),
    status: "PENDING",
    attempts: 0,
    lastError: null,
  };
  await db.sync_queue.insert(entry);
  return entry;
}

/** Oldest-first batch of work the engine is allowed to push right now. */
export async function claimBatch(limit = 100): Promise<SyncQueueEntry[]> {
  const db = await getDatabase();
  const docs = await db.sync_queue
    .find({
      selector: { status: { $in: ["PENDING", "FAILED"] } },
      sort: [{ clientTimestamp: "asc" }],
      limit,
    })
    .exec();

  if (docs.length === 0) return [];

  await Promise.all(
    docs.map((doc) =>
      doc.incrementalPatch({ status: "IN_FLIGHT" as const }).catch(() => {
        /* another tab claimed it first; harmless */
      }),
    ),
  );

  return docs.map((doc) => doc.toJSON() as SyncQueueEntry);
}

export async function acknowledge(entryIds: string[]): Promise<void> {
  if (entryIds.length === 0) return;
  const db = await getDatabase();
  const docs = await db.sync_queue
    .find({ selector: { id: { $in: entryIds } } })
    .exec();
  await Promise.all(docs.map((doc) => doc.remove()));
}

async function patchEntries(
  entryIds: string[],
  status: SyncQueueStatus,
  error: string,
): Promise<void> {
  if (entryIds.length === 0) return;
  const db = await getDatabase();
  const docs = await db.sync_queue
    .find({ selector: { id: { $in: entryIds } } })
    .exec();
  await Promise.all(
    docs.map((doc) =>
      doc.incrementalPatch({
        status,
        attempts: doc.attempts + 1,
        lastError: error.slice(0, 500) || null,
      }),
    ),
  );
}

/**
 * The server refused these changes on their merits. They stay FAILED until an
 * operator retries, because replaying them unchanged will just fail again.
 */
export function markFailed(entryIds: string[], error: string): Promise<void> {
  return patchEntries(entryIds, "FAILED", error);
}

/**
 * The server was unreachable or errored. Nothing is wrong with the changes, so
 * they go back to PENDING and the next cycle picks them up automatically —
 * which keeps the badge on "Sync pending [X]" rather than a red error state.
 */
export function requeue(entryIds: string[], error: string): Promise<void> {
  return patchEntries(entryIds, "PENDING", error);
}

/** Release IN_FLIGHT entries left behind by a tab that closed mid-push. */
export async function releaseStale(olderThanMs = 60_000): Promise<void> {
  const db = await getDatabase();
  const cutoff = new Date(Date.now() - olderThanMs).toISOString();
  const docs = await db.sync_queue
    .find({
      selector: {
        status: "IN_FLIGHT",
        clientTimestamp: { $lt: cutoff },
      },
    })
    .exec();
  await Promise.all(
    docs.map((doc) => doc.incrementalPatch({ status: "PENDING" as const })),
  );
}

/**
 * RxDB rejects `count()` unless the selector maps exactly onto an index (QU14),
 * and `$in` does not qualify. The outbox is bounded by unsynced edits, so
 * counting the matched documents is cheap and always correct.
 */
export async function countByStatus(
  statuses: SyncQueueStatus[],
): Promise<number> {
  const db = await getDatabase();
  const docs = await db.sync_queue
    .find({ selector: { status: { $in: statuses } } })
    .exec();
  return docs.length;
}

export function countPending(): Promise<number> {
  return countByStatus(["PENDING", "IN_FLIGHT"]);
}

/** Document ids with unsynced local edits — these win over incoming pull data. */
export async function pendingDocIds(table: EntityType): Promise<Set<string>> {
  const db = await getDatabase();
  const docs = await db.sync_queue.find({ selector: { table } }).exec();
  return new Set(docs.map((doc) => doc.docId));
}
