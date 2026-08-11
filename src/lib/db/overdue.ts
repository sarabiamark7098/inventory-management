"use client";

import { nowIso } from "@/lib/utils/id";
import { isOverdue } from "@/lib/domain/loan";
import type { BorrowingTransaction } from "@/lib/domain/types";
import { getDatabase } from "./database";
import { enqueue } from "./outbox";

const NOTIFIED_KEY = "ict.overdue.notified";

/**
 * Flips active loans past their due date to OVERDUE and queues the change
 * (US-3.4). Runs on the leader tab's sync tick and on app load.
 */
export async function sweepOverdue(): Promise<BorrowingTransaction[]> {
  const db = await getDatabase();
  const docs = await db.borrowings
    .find({
      selector: {
        status: { $in: ["ISSUED", "APPROVED"] },
        deletedAt: null,
      },
    })
    .exec();

  const flipped: BorrowingTransaction[] = [];
  for (const doc of docs) {
    const tx = doc.toJSON() as BorrowingTransaction;
    if (!isOverdue(tx)) continue;

    const ts = nowIso();
    await doc.incrementalPatch({ status: "OVERDUE" as const, updatedAt: ts });
    const after = await db.borrowings.findOne(tx.id).exec();
    await enqueue({
      table: "borrowings",
      action: "UPDATE",
      docId: tx.id,
      payload: after!.toJSON() as unknown as Record<string, unknown>,
    });
    flipped.push({ ...tx, status: "OVERDUE", updatedAt: ts });
  }
  return flipped;
}

/** Ids already surfaced to the operator, so one loan notifies only once. */
function readNotified(): Set<string> {
  try {
    const raw = window.localStorage.getItem(NOTIFIED_KEY);
    return new Set(raw ? (JSON.parse(raw) as string[]) : []);
  } catch {
    return new Set();
  }
}

function writeNotified(ids: Set<string>): void {
  try {
    window.localStorage.setItem(NOTIFIED_KEY, JSON.stringify([...ids]));
  } catch {
    /* storage full or blocked; notifications are best-effort */
  }
}

/**
 * Best-effort desktop notification for newly overdue loans. Silently does
 * nothing when the browser denies permission — the in-app banner is the
 * guaranteed channel.
 */
export async function notifyOverdue(
  overdueLoans: BorrowingTransaction[],
): Promise<void> {
  if (overdueLoans.length === 0) return;
  if (typeof window === "undefined" || !("Notification" in window)) return;

  const notified = readNotified();
  const fresh = overdueLoans.filter((tx) => !notified.has(tx.id));
  if (fresh.length === 0) return;

  if (Notification.permission === "default") {
    await Notification.requestPermission().catch(() => "denied");
  }
  if (Notification.permission !== "granted") return;

  for (const tx of fresh) {
    new Notification("Equipment overdue", {
      body: `${tx.borrowerName} — due ${new Date(tx.expectedReturnDate).toLocaleDateString()}`,
      tag: `overdue-${tx.id}`,
    });
    notified.add(tx.id);
  }
  writeNotified(notified);
}
