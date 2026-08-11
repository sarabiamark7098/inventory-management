"use client";

import { AlertTriangle, CalendarClock, Check, ScanLine } from "lucide-react";
import { useMemo, useState } from "react";

import { CodeScanner } from "@/components/equipment/code-scanner";
import { Modal } from "@/components/ui/modal";
import { Button, Field, Input, Textarea } from "@/components/ui/primitives";
import {
  ConditionBadge,
  EquipmentStatusBadge,
} from "@/components/ui/status-badge";
import { useCategories } from "@/lib/db/hooks";
import {
  checkoutEquipment,
  findEquipmentByCode,
  requestLoan,
} from "@/lib/db/repositories";
import { calculateExpectedReturn } from "@/lib/domain/loan";
import type { Equipment } from "@/lib/domain/types";

/** `2026-08-11T09:30:00.000Z` -> `2026-08-11T09:30` for datetime-local inputs. */
function toLocalInput(iso: string): string {
  const d = new Date(iso);
  const offset = d.getTimezoneOffset() * 60_000;
  return new Date(d.getTime() - offset).toISOString().slice(0, 16);
}

function fromLocalInput(value: string): string {
  return new Date(value).toISOString();
}

/**
 * Quick-scan checkout (US-3.1, US-3.2).
 *
 * Step 1 identifies the asset by scan or manual code; step 2 captures borrower
 * details with the due date pre-computed from the category's default loan days.
 */
export function CheckoutModal({
  open,
  onClose,
  presetEquipment,
  mode = "ISSUE",
}: {
  open: boolean;
  onClose: () => void;
  presetEquipment?: Equipment | null;
  /** ISSUE hands the device over now; REQUEST files a pending request. */
  mode?: "ISSUE" | "REQUEST";
}) {
  // A fresh mount per open gives a clean form without a reset effect.
  if (!open) return null;
  return (
    <CheckoutForm
      onClose={onClose}
      presetEquipment={presetEquipment ?? null}
      mode={mode}
    />
  );
}

function CheckoutForm({
  onClose,
  presetEquipment,
  mode,
}: {
  onClose: () => void;
  presetEquipment: Equipment | null;
  mode: "ISSUE" | "REQUEST";
}) {
  const { data: categories } = useCategories();

  const [equipment, setEquipment] = useState<Equipment | null>(presetEquipment);
  const [scanMiss, setScanMiss] = useState<string | null>(null);
  const [borrowerName, setBorrowerName] = useState("");
  const [borrowerEmail, setBorrowerEmail] = useState("");
  const [issueDate, setIssueDate] = useState(() =>
    toLocalInput(new Date().toISOString()),
  );
  /** `null` means "follow the category default"; a string is a manual override. */
  const [dueOverride, setDueOverride] = useState<string | null>(null);
  const [notes, setNotes] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const loanDays = useMemo(() => {
    if (!equipment?.categoryId) return 7;
    return (
      categories.find((c) => c.id === equipment.categoryId)?.defaultLoanDays ?? 7
    );
  }, [categories, equipment]);

  // Derived, not stored: the due date tracks the category default until the
  // operator types their own value (US-1.3).
  const dueTouched = dueOverride !== null;
  const dueDate =
    dueOverride ??
    (issueDate
      ? toLocalInput(calculateExpectedReturn(fromLocalInput(issueDate), loanDays))
      : "");

  async function handleScan(code: string) {
    const match = await findEquipmentByCode(code);
    if (!match) {
      setScanMiss(code);
      return;
    }
    setScanMiss(null);
    setEquipment(match);
  }

  async function submit() {
    if (!equipment) return setError("Scan or select an asset first.");
    if (!borrowerName.trim()) return setError("Borrower name is required.");
    if (!borrowerEmail.trim()) return setError("Borrower email is required.");
    if (!dueDate) return setError("Expected return date is required.");
    if (new Date(dueDate) <= new Date(issueDate))
      return setError("Expected return must be after the issue date.");

    setSaving(true);
    setError(null);
    try {
      const payload = {
        equipmentId: equipment.id,
        borrowerName,
        borrowerEmail,
        issueDate: fromLocalInput(issueDate),
        expectedReturnDate: fromLocalInput(dueDate),
        notes: notes || null,
      };
      if (mode === "REQUEST") {
        await requestLoan(payload);
      } else {
        await checkoutEquipment(payload);
      }
      onClose();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Checkout failed.");
    } finally {
      setSaving(false);
    }
  }

  const unavailable =
    mode === "ISSUE" && equipment !== null && equipment.status !== "AVAILABLE";

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title={mode === "REQUEST" ? "Request a loan" : "Issue equipment"}
      description={
        mode === "REQUEST"
          ? "Files a pending request an inventory manager can approve later."
          : "Scan the asset label, then confirm borrower details to hand the device over."
      }
      footer={
        <>
          <Button onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button
            variant="primary"
            onClick={() => void submit()}
            disabled={saving || !equipment || unavailable}
          >
            <Check className="size-4" />
            {saving
              ? "Saving…"
              : mode === "REQUEST"
                ? "Submit request"
                : "Confirm checkout"}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error ? (
          <p className="rounded-lg bg-red-500/10 px-3 py-2 text-xs text-red-700">
            {error}
          </p>
        ) : null}

        {equipment ? (
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border-subtle bg-surface-muted px-3 py-2.5">
            <div className="min-w-0">
              <span className="block font-mono text-sm font-semibold text-ink">
                {equipment.assetTag}
              </span>
              <span className="block truncate text-xs text-ink-muted">
                {equipment.brand ? `${equipment.brand} ` : ""}
                {equipment.modelName}
              </span>
            </div>
            <div className="flex items-center gap-2">
              <EquipmentStatusBadge status={equipment.status} />
              <ConditionBadge condition={equipment.condition} />
              <Button size="sm" onClick={() => setEquipment(null)}>
                <ScanLine className="size-3.5" />
                Change
              </Button>
            </div>
          </div>
        ) : (
          <>
            <CodeScanner onDetected={(code) => void handleScan(code)} />
            {scanMiss ? (
              <p className="rounded-lg bg-amber-500/10 px-3 py-2 text-xs text-amber-700">
                No equipment matches “{scanMiss}”.
              </p>
            ) : null}
          </>
        )}

        {unavailable ? (
          <p className="flex items-start gap-2 rounded-lg bg-amber-500/10 px-3 py-2 text-xs text-amber-700">
            <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
            This asset is {equipment?.status.toLowerCase()} and cannot be issued
            until it returns to AVAILABLE.
          </p>
        ) : null}

        {equipment ? (
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Borrower name" required>
              <Input
                value={borrowerName}
                placeholder="Ada Lovelace"
                onChange={(e) => setBorrowerName(e.target.value)}
              />
            </Field>
            <Field label="Borrower email" required>
              <Input
                type="email"
                value={borrowerEmail}
                placeholder="ada@example.org"
                onChange={(e) => setBorrowerEmail(e.target.value)}
              />
            </Field>

            <Field label="Issue date">
              <Input
                type="datetime-local"
                value={issueDate}
                onChange={(e) => setIssueDate(e.target.value)}
              />
            </Field>
            <Field
              label="Expected return"
              required
              hint={
                dueTouched
                  ? "Manually overridden"
                  : `Auto-calculated from the ${loanDays}-day category default`
              }
            >
              <Input
                type="datetime-local"
                value={dueDate}
                onChange={(e) => setDueOverride(e.target.value)}
              />
            </Field>

            <div className="sm:col-span-2">
              <Field label="Purpose / notes">
                <Textarea
                  rows={2}
                  value={notes}
                  placeholder="Field survey at the district office."
                  onChange={(e) => setNotes(e.target.value)}
                />
              </Field>
            </div>

            {!dueTouched ? (
              <p className="flex items-center gap-1.5 text-xs text-ink-muted sm:col-span-2">
                <CalendarClock className="size-3.5" />
                Due {dueDate ? new Date(dueDate).toLocaleString() : "—"}
              </p>
            ) : null}
          </div>
        ) : null}
      </div>
    </Modal>
  );
}
