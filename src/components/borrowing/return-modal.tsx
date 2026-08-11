"use client";

import { PackageCheck } from "lucide-react";
import { useState } from "react";

import { Modal } from "@/components/ui/modal";
import { Button, Field, Select, Textarea } from "@/components/ui/primitives";
import { returnEquipment } from "@/lib/db/repositories";
import { daysUntilDue, isOverdue } from "@/lib/domain/loan";
import {
  EQUIPMENT_CONDITIONS,
  type BorrowingTransaction,
  type Equipment,
  type EquipmentCondition,
} from "@/lib/domain/types";

const ACCESSORIES = [
  "Charger / power adapter",
  "Carrying case",
  "Mouse",
  "Cables",
  "Manual / documentation",
];

/**
 * Return inspection (US-3.3). Condition drives the asset's next status:
 * DAMAGED/POOR routes to MAINTENANCE, otherwise back to AVAILABLE.
 */
export function ReturnModal({
  open,
  transaction,
  equipment,
  onClose,
}: {
  open: boolean;
  transaction: BorrowingTransaction | null;
  equipment: Equipment | null;
  onClose: () => void;
}) {
  // Fresh mount per return keeps the inspection form from carrying over notes.
  if (!open || !transaction) return null;
  return (
    <ReturnForm
      key={transaction.id}
      transaction={transaction}
      equipment={equipment}
      onClose={onClose}
    />
  );
}

function ReturnForm({
  transaction,
  equipment,
  onClose,
}: {
  transaction: BorrowingTransaction;
  equipment: Equipment | null;
  onClose: () => void;
}) {
  const [condition, setCondition] = useState<EquipmentCondition>(
    equipment?.condition ?? "GOOD",
  );
  const [missing, setMissing] = useState<string[]>([]);
  const [damage, setDamage] = useState("");
  const [markLost, setMarkLost] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setSaving(true);
    setError(null);
    try {
      const notes = [
        `Condition on return: ${condition}`,
        missing.length > 0 ? `Missing accessories: ${missing.join(", ")}` : null,
        damage.trim() ? `Damage: ${damage.trim()}` : null,
        markLost ? "Asset reported LOST." : null,
      ]
        .filter(Boolean)
        .join(" | ");

      await returnEquipment({
        transactionId: transaction.id,
        condition,
        inspectionNotes: notes,
        markLost,
      });
      onClose();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not record return.");
    } finally {
      setSaving(false);
    }
  }

  const late = isOverdue(transaction);
  const lateDays = Math.abs(daysUntilDue(transaction.expectedReturnDate));

  return (
    <Modal
      open
      onClose={onClose}
      title="Process return"
      description={`${transaction.borrowerName} · ${equipment?.assetTag ?? "asset"}`}
      footer={
        <>
          <Button onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button
            variant="primary"
            onClick={() => void submit()}
            disabled={saving}
          >
            <PackageCheck className="size-4" />
            {saving ? "Saving…" : "Record return"}
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

        {late ? (
          <p className="rounded-lg bg-red-500/10 px-3 py-2 text-xs text-red-700">
            Returned {lateDays} day{lateDays === 1 ? "" : "s"} past the due date.
          </p>
        ) : null}

        <Field
          label="Condition on return"
          hint="POOR or DAMAGED sends the asset to MAINTENANCE instead of back to the shelf."
        >
          <Select
            value={condition}
            onChange={(e) =>
              setCondition(e.target.value as EquipmentCondition)
            }
          >
            {EQUIPMENT_CONDITIONS.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </Select>
        </Field>

        <fieldset className="space-y-1.5">
          <legend className="text-xs font-medium text-ink-muted">
            Missing accessories
          </legend>
          <div className="grid gap-1.5 sm:grid-cols-2">
            {ACCESSORIES.map((item) => (
              <label key={item} className="flex items-center gap-2 text-xs">
                <input
                  type="checkbox"
                  className="size-4 accent-[var(--color-brand)]"
                  checked={missing.includes(item)}
                  onChange={(e) =>
                    setMissing((prev) =>
                      e.target.checked
                        ? [...prev, item]
                        : prev.filter((v) => v !== item),
                    )
                  }
                />
                <span className="text-ink-muted">{item}</span>
              </label>
            ))}
          </div>
        </fieldset>

        <Field label="Damage / inspection notes">
          <Textarea
            rows={3}
            value={damage}
            placeholder="Cracked bezel on the top-left corner; keyboard backlight intermittent."
            onChange={(e) => setDamage(e.target.value)}
          />
        </Field>

        <label className="flex items-center gap-2 rounded-lg border border-border-subtle px-3 py-2">
          <input
            type="checkbox"
            className="size-4 accent-[var(--color-brand)]"
            checked={markLost}
            onChange={(e) => setMarkLost(e.target.checked)}
          />
          <span className="text-xs text-ink-muted">
            Asset was not returned — mark it <strong>LOST</strong>
          </span>
        </label>
      </div>
    </Modal>
  );
}
