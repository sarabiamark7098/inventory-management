/**
 * Assertions for the pure domain rules the sync engine and UI both depend on.
 *
 *   npm run check:domain
 */
import assert from "node:assert/strict";

import {
  calculateExpectedReturn,
  daysUntilDue,
  incomingCheckoutWins,
  isOverdue,
  loansOverlap,
} from "../src/lib/domain/loan.ts";
import { canTransitionEquipment } from "../src/lib/domain/types.ts";

let passed = 0;
function check(name: string, fn: () => void) {
  fn();
  passed++;
  console.log(`✓ ${name}`);
}

const DAY = 24 * 60 * 60 * 1000;
const T0 = "2026-08-11T09:00:00.000Z";

check("category default loan days set the due date", () => {
  assert.equal(calculateExpectedReturn(T0, 14), "2026-08-25T09:00:00.000Z");
  assert.equal(calculateExpectedReturn(T0, 0), T0);
});

check("negative loan durations are clamped, not applied backwards", () => {
  assert.equal(calculateExpectedReturn(T0, -5), T0);
});

check("only active loans can be overdue", () => {
  const past = { expectedReturnDate: "2026-08-01T00:00:00.000Z" };
  assert.equal(isOverdue({ ...past, status: "ISSUED" }), true);
  assert.equal(isOverdue({ ...past, status: "APPROVED" }), true);
  assert.equal(isOverdue({ ...past, status: "RETURNED" }), false);
  assert.equal(isOverdue({ ...past, status: "REQUESTED" }), false);
  assert.equal(isOverdue({ ...past, status: "CANCELLED" }), false);
});

check("daysUntilDue goes negative once past due", () => {
  const now = new Date(T0);
  assert.equal(daysUntilDue("2026-08-14T09:00:00.000Z", now), 3);
  assert.equal(daysUntilDue("2026-08-08T09:00:00.000Z", now), -3);
});

check("overlapping loan windows are detected", () => {
  const a = { issueDate: T0, expectedReturnDate: "2026-08-20T09:00:00.000Z" };
  const overlapping = {
    issueDate: "2026-08-15T09:00:00.000Z",
    expectedReturnDate: "2026-08-25T09:00:00.000Z",
  };
  const disjoint = {
    issueDate: "2026-08-21T09:00:00.000Z",
    expectedReturnDate: "2026-08-25T09:00:00.000Z",
  };
  assert.equal(loansOverlap(a, overlapping), true);
  assert.equal(loansOverlap(overlapping, a), true, "overlap is symmetric");
  assert.equal(loansOverlap(a, disjoint), false);
});

check("a loan fully contained in another still overlaps", () => {
  const outer = { issueDate: T0, expectedReturnDate: "2026-08-30T09:00:00.000Z" };
  const inner = {
    issueDate: "2026-08-14T09:00:00.000Z",
    expectedReturnDate: "2026-08-16T09:00:00.000Z",
  };
  assert.equal(loansOverlap(outer, inner), true);
  assert.equal(loansOverlap(inner, outer), true);
});

check("dual checkout resolves to the earlier creation time", () => {
  const early = { id: "bbb", createdAt: T0 };
  const late = { id: "aaa", createdAt: new Date(Date.parse(T0) + DAY) };
  assert.equal(incomingCheckoutWins(early, late), true);
  assert.equal(incomingCheckoutWins(late, early), false);
});

check("simultaneous checkouts break the tie deterministically on id", () => {
  const a = { id: "aaa", createdAt: T0 };
  const b = { id: "bbb", createdAt: T0 };
  assert.equal(incomingCheckoutWins(a, b), true);
  assert.equal(incomingCheckoutWins(b, a), false);
  // Both sides must never claim victory at once.
  assert.notEqual(incomingCheckoutWins(a, b), incomingCheckoutWins(b, a));
});

check("equipment status machine blocks illegal jumps", () => {
  assert.equal(canTransitionEquipment("AVAILABLE", "BORROWED"), true);
  assert.equal(canTransitionEquipment("BORROWED", "AVAILABLE"), true);
  assert.equal(canTransitionEquipment("BORROWED", "DECOMMISSIONED"), false);
  assert.equal(canTransitionEquipment("DECOMMISSIONED", "BORROWED"), false);
  // Idempotent writes from the sync engine must never be rejected.
  assert.equal(canTransitionEquipment("LOST", "LOST"), true);
});

console.log(`\n${passed} domain checks passed`);
