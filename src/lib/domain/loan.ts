import {
  ACTIVE_LOAN_STATUSES,
  type BorrowingStatus,
  type BorrowingTransaction,
} from "./types";

const MS_PER_DAY = 24 * 60 * 60 * 1000;

/** Auto-calculated return date from the category default loan duration (US-1.3). */
export function calculateExpectedReturn(
  issueDate: Date | string,
  loanDays: number,
): string {
  const start = typeof issueDate === "string" ? new Date(issueDate) : issueDate;
  const due = new Date(start.getTime() + Math.max(0, loanDays) * MS_PER_DAY);
  return due.toISOString();
}

export function isActiveLoan(status: BorrowingStatus): boolean {
  return ACTIVE_LOAN_STATUSES.includes(status);
}

export function isOverdue(
  tx: Pick<BorrowingTransaction, "status" | "expectedReturnDate">,
  now: Date = new Date(),
): boolean {
  if (!isActiveLoan(tx.status)) return false;
  return new Date(tx.expectedReturnDate).getTime() < now.getTime();
}

/** Negative when the loan is already past due. */
export function daysUntilDue(
  expectedReturnDate: string,
  now: Date = new Date(),
): number {
  const diff = new Date(expectedReturnDate).getTime() - now.getTime();
  return Math.ceil(diff / MS_PER_DAY);
}

/**
 * Two loans collide when both are physically active and their
 * [issue, expectedReturn] windows overlap. Used by the server conflict engine
 * to detect offline dual checkouts of the same asset.
 */
export function loansOverlap(
  a: Pick<BorrowingTransaction, "issueDate" | "expectedReturnDate">,
  b: Pick<BorrowingTransaction, "issueDate" | "expectedReturnDate">,
): boolean {
  const aStart = new Date(a.issueDate).getTime();
  const aEnd = new Date(a.expectedReturnDate).getTime();
  const bStart = new Date(b.issueDate).getTime();
  const bEnd = new Date(b.expectedReturnDate).getTime();
  return aStart <= bEnd && bStart <= aEnd;
}

export interface ConflictCandidate {
  id: string;
  createdAt: string | Date;
}

/**
 * Picks the winner of a dual offline checkout: the loan that was physically
 * created first keeps the asset, with the id as a tie-break so every client and
 * the server independently reach the same verdict.
 *
 * Returns true when `incoming` should keep the asset and `rival` gets flagged.
 */
export function incomingCheckoutWins(
  incoming: ConflictCandidate,
  rival: ConflictCandidate,
): boolean {
  const a = new Date(incoming.createdAt).getTime();
  const b = new Date(rival.createdAt).getTime();
  if (a !== b) return a < b;
  return incoming.id < rival.id;
}
