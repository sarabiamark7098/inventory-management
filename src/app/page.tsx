"use client";

import {
  AlertTriangle,
  ArrowLeftRight,
  ArrowRight,
  CircleCheck,
  Laptop,
  Plus,
  ShieldAlert,
  Wrench,
} from "lucide-react";
import Link from "next/link";
import { useMemo, useState } from "react";

import { CheckoutModal } from "@/components/borrowing/checkout-modal";
import { Button, Card, PageHeader, Stat } from "@/components/ui/primitives";
import {
  BorrowingStatusBadge,
  EquipmentStatusBadge,
} from "@/components/ui/status-badge";
import { useBorrowings, useCategoryMap, useEquipment } from "@/lib/db/hooks";
import { daysUntilDue, isOverdue } from "@/lib/domain/loan";

export default function DashboardPage() {
  const { data: equipment } = useEquipment();
  const { data: borrowings } = useBorrowings();
  const categories = useCategoryMap();
  const [checkoutOpen, setCheckoutOpen] = useState(false);

  const stats = useMemo(() => {
    const byStatus = new Map<string, number>();
    for (const item of equipment) {
      byStatus.set(item.status, (byStatus.get(item.status) ?? 0) + 1);
    }
    const active = borrowings.filter((tx) =>
      ["ISSUED", "APPROVED", "OVERDUE"].includes(tx.status),
    );
    return {
      total: equipment.length,
      available: byStatus.get("AVAILABLE") ?? 0,
      maintenance: byStatus.get("MAINTENANCE") ?? 0,
      lost: byStatus.get("LOST") ?? 0,
      onLoan: active.length,
      overdue: active.filter((tx) => isOverdue(tx)).length,
      requests: borrowings.filter((tx) => tx.status === "REQUESTED").length,
      conflicts: borrowings.filter(
        (tx) => tx.status === "CONFLICT_PENDING_REVIEW",
      ).length,
    };
  }, [equipment, borrowings]);

  const equipmentById = useMemo(
    () => new Map(equipment.map((e) => [e.id, e])),
    [equipment],
  );

  const dueSoon = useMemo(
    () =>
      borrowings
        .filter((tx) => ["ISSUED", "APPROVED", "OVERDUE"].includes(tx.status))
        .sort(
          (a, b) =>
            new Date(a.expectedReturnDate).getTime() -
            new Date(b.expectedReturnDate).getTime(),
        )
        .slice(0, 6),
    [borrowings],
  );

  const recentEquipment = useMemo(() => equipment.slice(0, 6), [equipment]);

  return (
    <>
      <PageHeader
        title="Dashboard"
        description="Live view of the local store. Everything here works without a network connection."
        actions={
          <>
            <Button variant="primary" onClick={() => setCheckoutOpen(true)}>
              <ArrowLeftRight className="size-4" />
              Quick checkout
            </Button>
            <Link href="/equipment">
              <Button>
                <Plus className="size-4" />
                Register asset
              </Button>
            </Link>
          </>
        }
      />

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Stat
          label="Total equipment"
          value={stats.total}
          icon={<Laptop className="size-4" />}
        />
        <Stat
          label="Available"
          value={stats.available}
          tone="good"
          icon={<CircleCheck className="size-4" />}
        />
        <Stat
          label="On loan"
          value={stats.onLoan}
          icon={<ArrowLeftRight className="size-4" />}
        />
        <Stat
          label="Overdue"
          value={stats.overdue}
          tone={stats.overdue > 0 ? "danger" : "default"}
          icon={<AlertTriangle className="size-4" />}
        />
      </div>

      {stats.conflicts > 0 || stats.requests > 0 || stats.maintenance > 0 ? (
        <div className="mt-3 grid gap-3 sm:grid-cols-3">
          {stats.requests > 0 ? (
            <Stat
              label="Pending requests"
              value={stats.requests}
              tone="warn"
              icon={<ArrowRight className="size-4" />}
            />
          ) : null}
          {stats.maintenance > 0 ? (
            <Stat
              label="In maintenance"
              value={stats.maintenance}
              tone="warn"
              icon={<Wrench className="size-4" />}
            />
          ) : null}
          {stats.conflicts > 0 ? (
            <Stat
              label="Sync conflicts"
              value={stats.conflicts}
              tone="danger"
              icon={<ShieldAlert className="size-4" />}
            />
          ) : null}
        </div>
      ) : null}

      <div className="mt-6 grid gap-4 lg:grid-cols-2">
        <Card className="overflow-hidden">
          <SectionHeader title="Due soonest" href="/borrowing" />
          {dueSoon.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-ink-muted">
              Nothing is on loan right now.
            </p>
          ) : (
            <ul className="divide-y divide-border-subtle">
              {dueSoon.map((tx) => {
                const overdue = isOverdue(tx);
                const remaining = daysUntilDue(tx.expectedReturnDate);
                return (
                  <li
                    key={tx.id}
                    className="flex items-center justify-between gap-3 px-4 py-2.5"
                  >
                    <div className="min-w-0">
                      <span className="block truncate text-xs font-medium text-ink">
                        {equipmentById.get(tx.equipmentId ?? "")?.assetTag ??
                          "—"}{" "}
                        · {tx.borrowerName}
                      </span>
                      <span className="block text-[11px] text-ink-muted">
                        {overdue
                          ? `${Math.abs(remaining)} day${Math.abs(remaining) === 1 ? "" : "s"} overdue`
                          : `due in ${remaining} day${remaining === 1 ? "" : "s"}`}
                      </span>
                    </div>
                    <BorrowingStatusBadge status={tx.status} overdue={overdue} />
                  </li>
                );
              })}
            </ul>
          )}
        </Card>

        <Card className="overflow-hidden">
          <SectionHeader title="Recently updated equipment" href="/equipment" />
          {recentEquipment.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-ink-muted">
              No equipment registered yet.
            </p>
          ) : (
            <ul className="divide-y divide-border-subtle">
              {recentEquipment.map((item) => (
                <li
                  key={item.id}
                  className="flex items-center justify-between gap-3 px-4 py-2.5"
                >
                  <div className="min-w-0">
                    <span className="block truncate font-mono text-xs font-medium text-ink">
                      {item.assetTag}
                    </span>
                    <span className="block truncate text-[11px] text-ink-muted">
                      {item.modelName}
                      {item.categoryId
                        ? ` · ${categories.get(item.categoryId)?.name ?? ""}`
                        : ""}
                    </span>
                  </div>
                  <EquipmentStatusBadge status={item.status} />
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <CheckoutModal open={checkoutOpen} onClose={() => setCheckoutOpen(false)} />
    </>
  );
}

function SectionHeader({ title, href }: { title: string; href: string }) {
  return (
    <div className="flex items-center justify-between border-b border-border-subtle px-4 py-2.5">
      <h2 className="text-xs font-semibold tracking-wide text-ink-muted uppercase">
        {title}
      </h2>
      <Link
        href={href}
        className="flex items-center gap-1 text-xs font-medium text-brand hover:underline"
      >
        View all
        <ArrowRight className="size-3" />
      </Link>
    </div>
  );
}
