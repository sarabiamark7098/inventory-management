"use client";

import { ChevronDown, ChevronUp, GripVertical, Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import {
  CUSTOM_FIELD_TYPES,
  type CustomFieldDefinition,
  type CustomFieldType,
} from "@/lib/domain/types";
import { Button, Input, Select } from "@/components/ui/primitives";

/** `Screen Size (inches)` -> `screen_size_inches` */
export function toFieldKey(label: string): string {
  return label
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "")
    .slice(0, 60);
}

export function validateFields(fields: CustomFieldDefinition[]): string | null {
  const seen = new Set<string>();
  for (const field of fields) {
    if (!field.label.trim()) return "Every custom field needs a label.";
    if (!field.key) return `Field "${field.label}" has no key.`;
    if (seen.has(field.key))
      return `Duplicate field key "${field.key}". Labels must be unique.`;
    seen.add(field.key);
    if (field.type === "select" && (field.options ?? []).length === 0)
      return `Field "${field.label}" is a dropdown but has no options.`;
  }
  return null;
}

/**
 * Builds the JSON schema stored in `categories.custom_fields` (US-1.2).
 * Equipment registered under the category renders one input per definition.
 */
export function CustomFieldsBuilder({
  fields,
  onChange,
}: {
  fields: CustomFieldDefinition[];
  onChange: (next: CustomFieldDefinition[]) => void;
}) {
  const [expanded, setExpanded] = useState<number | null>(null);

  function patch(index: number, patchValue: Partial<CustomFieldDefinition>) {
    onChange(
      fields.map((field, i) => (i === index ? { ...field, ...patchValue } : field)),
    );
  }

  function addField() {
    onChange([
      ...fields,
      { key: "", label: "", type: "text", required: false, options: [] },
    ]);
    setExpanded(fields.length);
  }

  function removeField(index: number) {
    onChange(fields.filter((_, i) => i !== index));
    setExpanded(null);
  }

  function move(index: number, delta: number) {
    const target = index + delta;
    if (target < 0 || target >= fields.length) return;
    const next = [...fields];
    [next[index], next[target]] = [next[target], next[index]];
    onChange(next);
    setExpanded(target);
  }

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between">
        <span className="text-xs font-medium text-ink-muted">
          Custom specification fields
        </span>
        <Button type="button" size="sm" onClick={addField}>
          <Plus className="size-3.5" />
          Add field
        </Button>
      </div>

      {fields.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border-subtle px-3 py-6 text-center text-xs text-ink-muted">
          No custom fields yet. Add fields like <em>RAM</em> or{" "}
          <em>MAC Address</em> to capture category-specific specs.
        </p>
      ) : null}

      <ul className="space-y-2">
        {fields.map((field, index) => {
          const isOpen = expanded === index;
          return (
            <li
              key={index}
              className="overflow-hidden rounded-lg border border-border-subtle bg-surface"
            >
              <div className="flex items-center gap-2 px-2 py-2">
                <GripVertical className="size-4 shrink-0 text-ink-muted/60" />
                <button
                  type="button"
                  onClick={() => setExpanded(isOpen ? null : index)}
                  className="flex min-w-0 flex-1 items-center gap-2 text-left"
                >
                  <span className="truncate text-sm font-medium text-ink">
                    {field.label || "Untitled field"}
                  </span>
                  <span className="rounded bg-surface-sunken px-1.5 py-0.5 font-mono text-[10px] text-ink-muted">
                    {field.type}
                  </span>
                  {field.required ? (
                    <span className="text-[10px] font-medium text-red-500">
                      required
                    </span>
                  ) : null}
                </button>

                <div className="flex shrink-0 items-center">
                  <IconButton
                    label="Move up"
                    disabled={index === 0}
                    onClick={() => move(index, -1)}
                  >
                    <ChevronUp className="size-4" />
                  </IconButton>
                  <IconButton
                    label="Move down"
                    disabled={index === fields.length - 1}
                    onClick={() => move(index, 1)}
                  >
                    <ChevronDown className="size-4" />
                  </IconButton>
                  <IconButton
                    label="Remove field"
                    onClick={() => removeField(index)}
                  >
                    <Trash2 className="size-4 text-red-500" />
                  </IconButton>
                </div>
              </div>

              {isOpen ? (
                <div className="grid gap-3 border-t border-border-subtle bg-surface-muted px-3 py-3 sm:grid-cols-2">
                  <label className="space-y-1">
                    <span className="text-xs text-ink-muted">Label</span>
                    <Input
                      value={field.label}
                      placeholder="e.g. Processor"
                      onChange={(e) => {
                        const label = e.target.value;
                        // Keep the key locked once the user edits it by hand.
                        const key =
                          field.key && field.key !== toFieldKey(field.label)
                            ? field.key
                            : toFieldKey(label);
                        patch(index, { label, key });
                      }}
                    />
                  </label>

                  <label className="space-y-1">
                    <span className="text-xs text-ink-muted">
                      Key (stored in specifications)
                    </span>
                    <Input
                      value={field.key}
                      placeholder="processor"
                      className="font-mono"
                      onChange={(e) =>
                        patch(index, { key: toFieldKey(e.target.value) })
                      }
                    />
                  </label>

                  <label className="space-y-1">
                    <span className="text-xs text-ink-muted">Type</span>
                    <Select
                      value={field.type}
                      onChange={(e) =>
                        patch(index, {
                          type: e.target.value as CustomFieldType,
                        })
                      }
                    >
                      {CUSTOM_FIELD_TYPES.map((type) => (
                        <option key={type} value={type}>
                          {type}
                        </option>
                      ))}
                    </Select>
                  </label>

                  <label className="flex items-center gap-2 self-end pb-2">
                    <input
                      type="checkbox"
                      className="size-4 accent-[var(--color-brand)]"
                      checked={field.required}
                      onChange={(e) =>
                        patch(index, { required: e.target.checked })
                      }
                    />
                    <span className="text-xs text-ink-muted">
                      Required when registering equipment
                    </span>
                  </label>

                  {field.type === "select" ? (
                    <label className="space-y-1 sm:col-span-2">
                      <span className="text-xs text-ink-muted">
                        Dropdown options (comma separated)
                      </span>
                      <Input
                        value={(field.options ?? []).join(", ")}
                        placeholder="8GB, 16GB, 32GB"
                        onChange={(e) =>
                          patch(index, {
                            options: e.target.value
                              .split(",")
                              .map((o) => o.trim())
                              .filter(Boolean),
                          })
                        }
                      />
                    </label>
                  ) : (
                    <label className="space-y-1 sm:col-span-2">
                      <span className="text-xs text-ink-muted">
                        Placeholder (optional)
                      </span>
                      <Input
                        value={field.placeholder ?? ""}
                        onChange={(e) =>
                          patch(index, { placeholder: e.target.value })
                        }
                      />
                    </label>
                  )}
                </div>
              ) : null}
            </li>
          );
        })}
      </ul>
    </div>
  );
}

function IconButton({
  label,
  disabled,
  onClick,
  children,
}: {
  label: string;
  disabled?: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      disabled={disabled}
      onClick={onClick}
      className="rounded-md p-1.5 text-ink-muted transition-colors hover:bg-surface-sunken disabled:opacity-30 disabled:hover:bg-transparent"
    >
      {children}
    </button>
  );
}
