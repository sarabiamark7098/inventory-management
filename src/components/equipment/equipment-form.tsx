"use client";

import { useMemo, useState } from "react";

import { Modal } from "@/components/ui/modal";
import { Button, Field, Input, Select } from "@/components/ui/primitives";
import { useCategories } from "@/lib/db/hooks";
import { createEquipment, updateEquipment } from "@/lib/db/repositories";
import {
  EQUIPMENT_CONDITIONS,
  EQUIPMENT_STATUSES,
  EQUIPMENT_STATUS_TRANSITIONS,
  canTransitionEquipment,
  type Equipment,
  type EquipmentCondition,
  type EquipmentStatus,
} from "@/lib/domain/types";
import {
  SpecificationFields,
  validateSpecifications,
} from "./specification-fields";

interface FormState {
  assetTag: string;
  serialNumber: string;
  categoryId: string;
  modelName: string;
  brand: string;
  status: EquipmentStatus;
  condition: EquipmentCondition;
  location: string;
  specifications: Record<string, unknown>;
}

function toForm(equipment: Equipment | null): FormState {
  return {
    assetTag: equipment?.assetTag ?? "",
    serialNumber: equipment?.serialNumber ?? "",
    categoryId: equipment?.categoryId ?? "",
    modelName: equipment?.modelName ?? "",
    brand: equipment?.brand ?? "",
    status: equipment?.status ?? "AVAILABLE",
    condition: equipment?.condition ?? "GOOD",
    location: equipment?.location ?? "",
    specifications: equipment?.specifications ?? {},
  };
}

/**
 * Mounting the form only while open means every open starts from fresh state
 * initialisers — no reset-on-prop-change effect needed.
 */
export function EquipmentFormModal({
  open,
  equipment,
  onClose,
}: {
  open: boolean;
  /** `null` opens the form in create mode. */
  equipment: Equipment | null;
  onClose: () => void;
}) {
  if (!open) return null;
  return (
    <EquipmentForm
      key={equipment?.id ?? "new"}
      equipment={equipment}
      onClose={onClose}
    />
  );
}

function EquipmentForm({
  equipment,
  onClose,
}: {
  equipment: Equipment | null;
  onClose: () => void;
}) {
  const { data: categories } = useCategories();
  const [form, setForm] = useState<FormState>(() => toForm(equipment));
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const customFields = useMemo(() => {
    const category = categories.find((c) => c.id === form.categoryId);
    return category?.customFields?.fields ?? [];
  }, [categories, form.categoryId]);

  // Only offer transitions the state machine allows (US-2.3).
  const statusOptions = useMemo(() => {
    if (!equipment) return EQUIPMENT_STATUSES;
    return EQUIPMENT_STATUSES.filter((status) =>
      canTransitionEquipment(equipment.status, status),
    );
  }, [equipment]);

  async function save() {
    if (!form.assetTag.trim()) return setError("Asset tag is required.");
    if (!form.modelName.trim()) return setError("Model name is required.");

    const specError = validateSpecifications(customFields, form.specifications);
    if (specError) return setError(specError);

    if (equipment && !canTransitionEquipment(equipment.status, form.status)) {
      return setError(
        `Cannot move from ${equipment.status} to ${form.status}. Allowed: ${EQUIPMENT_STATUS_TRANSITIONS[equipment.status].join(", ")}.`,
      );
    }

    setSaving(true);
    setError(null);
    try {
      const payload = {
        assetTag: form.assetTag,
        serialNumber: form.serialNumber || null,
        categoryId: form.categoryId || null,
        modelName: form.modelName,
        brand: form.brand || null,
        status: form.status,
        condition: form.condition,
        location: form.location || null,
        specifications: form.specifications,
      };
      if (equipment) {
        await updateEquipment(equipment.id, payload);
      } else {
        await createEquipment(payload);
      }
      onClose();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not save equipment.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title={equipment ? `Edit ${equipment.assetTag}` : "Register equipment"}
      description="Specification inputs come from the selected category's custom field definitions."
      footer={
        <>
          <Button onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button variant="primary" onClick={() => void save()} disabled={saving}>
            {saving ? "Saving…" : equipment ? "Save changes" : "Register"}
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

        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Asset tag" required hint="Printed on the QR label">
            <Input
              value={form.assetTag}
              placeholder="ICT-0001"
              className="font-mono"
              onChange={(e) => setForm({ ...form, assetTag: e.target.value })}
            />
          </Field>
          <Field label="Serial number">
            <Input
              value={form.serialNumber}
              className="font-mono"
              onChange={(e) =>
                setForm({ ...form, serialNumber: e.target.value })
              }
            />
          </Field>
          <Field label="Model name" required>
            <Input
              value={form.modelName}
              placeholder="ThinkPad T14 Gen 4"
              onChange={(e) => setForm({ ...form, modelName: e.target.value })}
            />
          </Field>
          <Field label="Brand">
            <Input
              value={form.brand}
              placeholder="Lenovo"
              onChange={(e) => setForm({ ...form, brand: e.target.value })}
            />
          </Field>

          <Field label="Category">
            <Select
              value={form.categoryId}
              onChange={(e) =>
                // Specs belong to the old category's schema; start clean.
                setForm({
                  ...form,
                  categoryId: e.target.value,
                  specifications: {},
                })
              }
            >
              <option value="">Uncategorised</option>
              {categories.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Location">
            <Input
              value={form.location}
              placeholder="ICT Storeroom / Rack B"
              onChange={(e) => setForm({ ...form, location: e.target.value })}
            />
          </Field>

          <Field label="Status">
            <Select
              value={form.status}
              onChange={(e) =>
                setForm({ ...form, status: e.target.value as EquipmentStatus })
              }
            >
              {statusOptions.map((status) => (
                <option key={status} value={status}>
                  {status}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Condition">
            <Select
              value={form.condition}
              onChange={(e) =>
                setForm({
                  ...form,
                  condition: e.target.value as EquipmentCondition,
                })
              }
            >
              {EQUIPMENT_CONDITIONS.map((condition) => (
                <option key={condition} value={condition}>
                  {condition}
                </option>
              ))}
            </Select>
          </Field>
        </div>

        <div className="space-y-2">
          <span className="text-xs font-medium text-ink-muted">
            Specifications
          </span>
          <SpecificationFields
            fields={customFields}
            values={form.specifications}
            onChange={(specifications) => setForm({ ...form, specifications })}
          />
        </div>
      </div>
    </Modal>
  );
}

/** Read-only detail panel used from the scanner lookup result. */
export function SpecificationList({
  specifications,
}: {
  specifications: Record<string, unknown>;
}) {
  const entries = Object.entries(specifications ?? {}).filter(
    ([, value]) => value !== null && value !== undefined && value !== "",
  );
  if (entries.length === 0) {
    return <p className="text-xs text-ink-muted">No specifications recorded.</p>;
  }
  return (
    <dl className="grid gap-x-4 gap-y-1 sm:grid-cols-2">
      {entries.map(([key, value]) => (
        <div key={key} className="flex justify-between gap-2 text-xs">
          <dt className="font-mono text-ink-muted">{key}</dt>
          <dd className="text-right font-medium text-ink">
            {typeof value === "boolean" ? (value ? "Yes" : "No") : String(value)}
          </dd>
        </div>
      ))}
    </dl>
  );
}
