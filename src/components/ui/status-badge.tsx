"use client";

import type { ReactNode } from "react";

import type {
  BorrowingStatus,
  EquipmentCondition,
  EquipmentStatus,
} from "@/lib/domain/types";
import { cx } from "./primitives";

const TONES = {
  neutral: "bg-surface-sunken text-ink-muted ring-border-subtle",
  green: "bg-emerald-500/10 text-emerald-700 ring-emerald-500/25",
  blue: "bg-blue-500/10 text-blue-700 ring-blue-500/25",
  amber: "bg-amber-500/10 text-amber-700 ring-amber-500/25",
  red: "bg-red-500/10 text-red-700 ring-red-500/25",
  purple: "bg-purple-500/10 text-purple-700 ring-purple-500/25",
} as const;

type Tone = keyof typeof TONES;

export function Badge({
  tone = "neutral",
  className,
  children,
}: {
  tone?: Tone;
  className?: string;
  children: ReactNode;
}) {
  return (
    <span
      className={cx(
        "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap ring-1 ring-inset",
        TONES[tone],
        className,
      )}
    >
      {children}
    </span>
  );
}

const EQUIPMENT_TONES: Record<EquipmentStatus, Tone> = {
  AVAILABLE: "green",
  BORROWED: "blue",
  MAINTENANCE: "amber",
  DECOMMISSIONED: "neutral",
  LOST: "red",
};

export function EquipmentStatusBadge({ status }: { status: EquipmentStatus }) {
  return <Badge tone={EQUIPMENT_TONES[status] ?? "neutral"}>{status}</Badge>;
}

const CONDITION_TONES: Record<EquipmentCondition, Tone> = {
  NEW: "green",
  GOOD: "green",
  FAIR: "amber",
  POOR: "amber",
  DAMAGED: "red",
};

export function ConditionBadge({
  condition,
}: {
  condition: EquipmentCondition;
}) {
  return <Badge tone={CONDITION_TONES[condition] ?? "neutral"}>{condition}</Badge>;
}

const BORROWING_TONES: Record<BorrowingStatus, Tone> = {
  REQUESTED: "amber",
  APPROVED: "blue",
  ISSUED: "blue",
  RETURNED: "green",
  OVERDUE: "red",
  REJECTED: "neutral",
  CANCELLED: "neutral",
  CONFLICT_PENDING_REVIEW: "purple",
};

const BORROWING_LABELS: Partial<Record<BorrowingStatus, string>> = {
  CONFLICT_PENDING_REVIEW: "CONFLICT",
};

export function BorrowingStatusBadge({
  status,
  overdue,
}: {
  status: BorrowingStatus;
  /** Renders an active-but-past-due loan as OVERDUE without mutating the record. */
  overdue?: boolean;
}) {
  const effective: BorrowingStatus = overdue ? "OVERDUE" : status;
  return (
    <Badge tone={BORROWING_TONES[effective] ?? "neutral"}>
      {BORROWING_LABELS[effective] ?? effective}
    </Badge>
  );
}
