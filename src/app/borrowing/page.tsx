"use client";

import {
  AlertTriangle,
  ArrowLeftRight,
  Check,
  Download,
  PackageCheck,
  Search,
  ShieldAlert,
  X,
} from "lucide-react";
import { useEffect, useMemo, useState } from "react";

import { CheckoutModal } from "@/components/borrowing/checkout-modal";
import { ReturnModal } from "@/components/borrowing/return-modal";
import {
  Button,
  Card,
  EmptyState,
  Input,
  PageHeader,
  cx,
} from "@/components/ui/primitives";
import { BorrowingStatusBadge } from "@/components/ui/status-badge";
import { useBorrowings, useEquipment } from "@/lib/db/hooks";
import { notifyOverdue, sweepOverdue } from "@/lib/db/overdue";
import { approveRequest, setBorrowingStatus } from "@/lib/db/repositories";
import { daysUntilDue, isOverdue } from "@/lib/domain/loan";
import type { BorrowingTransaction, Equipment } from "@/lib/domain/types";
import { downloadCsv, timestampedFilename, toCsv } from "@/lib/utils/csv";

type Tab = "active" | "requests" | "conflicts" | "history";

const TABS: Array<{ id: Tab; label: string }> = [
  { id: "active", label: "On loan" },
  { id: "requests", label: "Requests" },
  { id: "conflicts", label: "Conflicts" },
  { id: "history", label: "History" },
];

export default function BorrowingPage() {
  const { data: borrowings, loading } = useBorrowings();
  const { data: equipment } = useEquipment();

  const [tab, setTab] = useState<Tab>("active");
  const [search, setSearch] = useState("");
  const [checkoutOpen, setCheckoutOpen] = useState(false);
  const [returning, setReturning] = useState<BorrowingTransaction | null>(null);

  const equipmentById = useMemo(
    () => new Map(equipment.map((e) => [e.id, e])),
    [equipment],
  );

  // Promote past-due loans on load; the sync engine repeats this each cycle.
  useEffect(() => {
    void (async () => {
      const flipped = await sweepOverdue();
      await notifyOverdue(flipped);
    })();
  }, []);

  const buckets = useMemo(() => {
    const active: BorrowingTransaction[] = [];
    const requests: BorrowingTransaction[] = [];
    const conflicts: BorrowingTransaction[] = [];
    const history: BorrowingTransaction[] = [];

    for (const tx of borrowings) {
      switch (tx.status) {
        case "ISSUED":
        case "APPROVED":
        case "OVERDUE":
          active.push(tx);
          break;
        case "REQUESTED":
          requests.push(tx);
          break;
        case "CONFLICT_PENDING_REVIEW":
          conflicts.push(tx);
          break;
        default:
          history.push(tx);
      }
    }
    return { active, requests, conflicts, history };
  }, [borrowings]);

  const overdueCount = buckets.active.filter((tx) => isOverdue(tx)).length;

  const rows = useMemo(() => {
    const source = buckets[tab];
    const term = search.trim().toLowerCase();
    if (!term) return source;
    return source.filter((tx) => {
      const asset = equipmentById.get(tx.equipmentId ?? "");
      return [
        tx.borrowerName,
        tx.borrowerEmail,
        tx.notes,
        asset?.assetTag,
        asset?.modelName,
        asset?.serialNumber,
      ]
        .filter(Boolean)
        .join(" ")
        .toLowerCase()
        .includes(term);
    });
  }, [buckets, tab, search, equipmentById]);

  function exportCsv() {
    const csv = toCsv(rows, [
      {
        header: "Asset Tag",
        value: (r) => equipmentById.get(r.equipmentId ?? "")?.assetTag ?? "",
      },
      {
        header: "Model",
        value: (r) => equipmentById.get(r.equipmentId ?? "")?.modelName ?? "",
      },
      { header: "Borrower", value: (r) => r.borrowerName },
      { header: "Email", value: (r) => r.borrowerEmail },
      { header: "Issued", value: (r) => r.issueDate },
      { header: "Due", value: (r) => r.expectedReturnDate },
      { header: "Returned", value: (r) => r.actualReturnDate },
      { header: "Status", value: (r) => r.status },
      { header: "Conflict", value: (r) => r.conflictReason },
      { header: "Notes", value: (r) => r.notes },
    ]);
    downloadCsv(timestampedFilename(`ict-borrowing-${tab}`), csv);
  }

  return (
    <>
      <PageHeader
        title="Borrowing & returns"
        description="Issue equipment with a quick scan, track due dates, and record return inspections."
        actions={
          <>
            <Button onClick={exportCsv} disabled={rows.length === 0}>
              <Download className="size-4" />
              Export
            </Button>
            <Button variant="primary" onClick={() => setCheckoutOpen(true)}>
              <ArrowLeftRight className="size-4" />
              Quick checkout
            </Button>
          </>
        }
      />

      {overdueCount > 0 ? (
        <div className="mb-4 flex items-start gap-2 rounded-lg bg-red-500/10 px-3 py-2.5 text-sm text-red-700">
          <AlertTriangle className="mt-0.5 size-4 shrink-0" />
          <span>
            <strong>{overdueCount}</strong> loan
            {overdueCount === 1 ? " is" : "s are"} past the expected return date.
          </span>
        </div>
      ) : null}

      {buckets.conflicts.length > 0 && tab !== "conflicts" ? (
        <button
          type="button"
          onClick={() => setTab("conflicts")}
          className="mb-4 flex w-full items-start gap-2 rounded-lg bg-purple-500/10 px-3 py-2.5 text-left text-sm text-purple-700"
        >
          <ShieldAlert className="mt-0.5 size-4 shrink-0" />
          <span>
            <strong>{buckets.conflicts.length}</strong> transaction
            {buckets.conflicts.length === 1 ? "" : "s"} flagged by the server as a
            dual checkout. Review required.
          </span>
        </button>
      ) : null}

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <div className="flex flex-wrap gap-1 rounded-lg border border-border-subtle bg-surface p-1">
          {TABS.map((item) => (
            <button
              key={item.id}
              type="button"
              onClick={() => setTab(item.id)}
              className={cx(
                "rounded-md px-3 py-1.5 text-xs font-medium transition-colors",
                tab === item.id
                  ? "bg-brand-soft text-brand"
                  : "text-ink-muted hover:bg-surface-sunken hover:text-ink",
              )}
            >
              {item.label}
              <span className="ml-1.5 text-[10px] opacity-70">
                {buckets[item.id].length}
              </span>
            </button>
          ))}
        </div>

        <div className="relative ml-auto w-full sm:w-72">
          <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-muted" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search borrower or asset…"
            className="pl-9"
            aria-label="Search transactions"
          />
        </div>
      </div>

      <Card className="overflow-hidden">
        {loading ? (
          <div className="px-6 py-16 text-center text-sm text-ink-muted">
            Loading local database…
          </div>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={<ArrowLeftRight className="size-8" />}
            title={`Nothing in ${TABS.find((t) => t.id === tab)?.label.toLowerCase()}`}
            description={
              tab === "active"
                ? "Issue a device with Quick checkout to see it here."
                : undefined
            }
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border-subtle bg-surface-muted text-xs text-ink-muted">
                <tr>
                  <th className="px-4 py-2.5 font-medium">Asset</th>
                  <th className="px-4 py-2.5 font-medium">Borrower</th>
                  <th className="px-4 py-2.5 font-medium">Issued</th>
                  <th className="px-4 py-2.5 font-medium">Due</th>
                  <th className="px-4 py-2.5 font-medium">Status</th>
                  <th className="px-4 py-2.5 text-right font-medium">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border-subtle">
                {rows.map((tx) => (
                  <TransactionRow
                    key={tx.id}
                    tx={tx}
                    asset={equipmentById.get(tx.equipmentId ?? "") ?? null}
                    onReturn={() => setReturning(tx)}
                  />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <CheckoutModal
        open={checkoutOpen}
        onClose={() => setCheckoutOpen(false)}
      />

      <ReturnModal
        open={returning !== null}
        transaction={returning}
        equipment={
          returning ? (equipmentById.get(returning.equipmentId ?? "") ?? null) : null
        }
        onClose={() => setReturning(null)}
      />
    </>
  );
}

function TransactionRow({
  tx,
  asset,
  onReturn,
}: {
  tx: BorrowingTransaction;
  asset: Equipment | null;
  onReturn: () => void;
}) {
  const overdue = isOverdue(tx);
  const remaining = daysUntilDue(tx.expectedReturnDate);
  const active = ["ISSUED", "APPROVED", "OVERDUE"].includes(tx.status);

  return (
    <tr className="hover:bg-surface-muted/60">
      <td className="px-4 py-2.5">
        <span className="block font-mono text-xs font-semibold text-ink">
          {asset?.assetTag ?? "—"}
        </span>
        <span className="block text-xs text-ink-muted">
          {asset?.modelName ?? "Asset removed"}
        </span>
      </td>
      <td className="px-4 py-2.5">
        <span className="block text-xs font-medium text-ink">
          {tx.borrowerName}
        </span>
        <span className="block text-xs text-ink-muted">{tx.borrowerEmail}</span>
      </td>
      <td className="px-4 py-2.5 text-xs text-ink-muted">
        {new Date(tx.issueDate).toLocaleDateString()}
      </td>
      <td className="px-4 py-2.5 text-xs">
        <span className={overdue ? "font-medium text-red-600" : "text-ink-muted"}>
          {new Date(tx.expectedReturnDate).toLocaleDateString()}
        </span>
        {active ? (
          <span className="block text-[11px] text-ink-muted">
            {overdue
              ? `${Math.abs(remaining)}d overdue`
              : `${remaining}d remaining`}
          </span>
        ) : tx.actualReturnDate ? (
          <span className="block text-[11px] text-ink-muted">
            returned {new Date(tx.actualReturnDate).toLocaleDateString()}
          </span>
        ) : null}
      </td>
      <td className="px-4 py-2.5">
        <BorrowingStatusBadge status={tx.status} overdue={overdue} />
        {tx.conflictReason ? (
          <span className="mt-1 block max-w-56 text-[11px] text-purple-700">
            {tx.conflictReason}
          </span>
        ) : null}
      </td>
      <td className="px-4 py-2.5">
        <div className="flex justify-end gap-1">
          {tx.status === "REQUESTED" ? (
            <>
              <Button
                size="sm"
                variant="primary"
                onClick={() => void approveRequest(tx.id)}
              >
                <Check className="size-3.5" />
                Approve
              </Button>
              <Button
                size="sm"
                onClick={() => void setBorrowingStatus(tx.id, "REJECTED")}
              >
                <X className="size-3.5" />
                Reject
              </Button>
            </>
          ) : null}

          {["ISSUED", "APPROVED", "OVERDUE", "CONFLICT_PENDING_REVIEW"].includes(
            tx.status,
          ) ? (
            <Button size="sm" variant="primary" onClick={onReturn}>
              <PackageCheck className="size-3.5" />
              Return
            </Button>
          ) : null}
        </div>
      </td>
    </tr>
  );
}
