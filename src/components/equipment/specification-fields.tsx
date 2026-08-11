"use client";

import { Field, Input, Select } from "@/components/ui/primitives";
import type { CustomFieldDefinition } from "@/lib/domain/types";

/** Renders one input per category-defined custom field (US-1.2 -> US-2.1). */
export function SpecificationFields({
  fields,
  values,
  onChange,
}: {
  fields: CustomFieldDefinition[];
  values: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}) {
  if (fields.length === 0) {
    return (
      <p className="rounded-lg border border-dashed border-border-subtle px-3 py-4 text-center text-xs text-ink-muted">
        The selected category defines no custom fields.
      </p>
    );
  }

  function set(key: string, value: unknown) {
    onChange({ ...values, [key]: value });
  }

  return (
    <div className="grid gap-3 sm:grid-cols-2">
      {fields.map((field) => {
        const value = values[field.key];

        if (field.type === "boolean") {
          return (
            <label
              key={field.key}
              className="flex items-center gap-2 self-end pb-2"
            >
              <input
                type="checkbox"
                className="size-4 accent-[var(--color-brand)]"
                checked={Boolean(value)}
                onChange={(e) => set(field.key, e.target.checked)}
              />
              <span className="text-xs text-ink-muted">
                {field.label}
                {field.required ? <span className="text-red-500"> *</span> : null}
              </span>
            </label>
          );
        }

        return (
          <Field key={field.key} label={field.label} required={field.required}>
            {field.type === "select" ? (
              <Select
                value={typeof value === "string" ? value : ""}
                onChange={(e) => set(field.key, e.target.value)}
              >
                <option value="">—</option>
                {(field.options ?? []).map((option) => (
                  <option key={option} value={option}>
                    {option}
                  </option>
                ))}
              </Select>
            ) : (
              <Input
                type={
                  field.type === "number"
                    ? "number"
                    : field.type === "date"
                      ? "date"
                      : "text"
                }
                placeholder={field.placeholder}
                value={
                  value === undefined || value === null ? "" : String(value)
                }
                onChange={(e) =>
                  set(
                    field.key,
                    field.type === "number"
                      ? e.target.value === ""
                        ? null
                        : Number(e.target.value)
                      : e.target.value,
                  )
                }
              />
            )}
          </Field>
        );
      })}
    </div>
  );
}

/** Blocks save when a required custom field is empty. */
export function validateSpecifications(
  fields: CustomFieldDefinition[],
  values: Record<string, unknown>,
): string | null {
  for (const field of fields) {
    if (!field.required) continue;
    const value = values[field.key];
    const empty =
      value === undefined ||
      value === null ||
      value === "" ||
      (field.type === "boolean" && value === false);
    if (empty) return `"${field.label}" is required for this category.`;
  }
  return null;
}
