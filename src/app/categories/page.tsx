"use client";

import { CalendarClock, Pencil, Plus, Tags, Trash2 } from "lucide-react";
import { useMemo, useState } from "react";

import {
  CustomFieldsBuilder,
  validateFields,
} from "@/components/categories/custom-fields-builder";
import { ConfirmDialog, Modal } from "@/components/ui/modal";
import {
  Button,
  Card,
  EmptyState,
  Field,
  Input,
  PageHeader,
  Textarea,
} from "@/components/ui/primitives";
import { useCategories, useEquipment } from "@/lib/db/hooks";
import {
  createCategory,
  deleteCategory,
  updateCategory,
} from "@/lib/db/repositories";
import type { Category, CustomFieldDefinition } from "@/lib/domain/types";

interface FormState {
  id: string | null;
  name: string;
  description: string;
  defaultLoanDays: number;
  fields: CustomFieldDefinition[];
}

const BLANK: FormState = {
  id: null,
  name: "",
  description: "",
  defaultLoanDays: 7,
  fields: [],
};

export default function CategoriesPage() {
  const { data: categories, loading } = useCategories();
  const { data: equipment } = useEquipment();
  const [form, setForm] = useState<FormState | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [pendingDelete, setPendingDelete] = useState<Category | null>(null);

  const usageByCategory = useMemo(() => {
    const counts = new Map<string, number>();
    for (const item of equipment) {
      if (!item.categoryId) continue;
      counts.set(item.categoryId, (counts.get(item.categoryId) ?? 0) + 1);
    }
    return counts;
  }, [equipment]);

  function openCreate() {
    setError(null);
    setForm({ ...BLANK });
  }

  function openEdit(category: Category) {
    setError(null);
    setForm({
      id: category.id,
      name: category.name,
      description: category.description ?? "",
      defaultLoanDays: category.defaultLoanDays,
      fields: category.customFields?.fields ?? [],
    });
  }

  async function save() {
    if (!form) return;
    if (!form.name.trim()) {
      setError("Category name is required.");
      return;
    }
    const fieldError = validateFields(form.fields);
    if (fieldError) {
      setError(fieldError);
      return;
    }

    setSaving(true);
    setError(null);
    try {
      const payload = {
        name: form.name,
        description: form.description,
        defaultLoanDays: Number(form.defaultLoanDays) || 0,
        customFields: { fields: form.fields },
      };
      if (form.id) {
        await updateCategory(form.id, payload);
      } else {
        await createCategory(payload);
      }
      setForm(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not save category.");
    } finally {
      setSaving(false);
    }
  }

  async function confirmDelete() {
    if (!pendingDelete) return;
    await deleteCategory(pendingDelete.id);
    setPendingDelete(null);
  }

  return (
    <>
      <PageHeader
        title="Categories"
        description="Group equipment, define the custom specification fields each group captures, and set default loan durations."
        actions={
          <Button variant="primary" onClick={openCreate}>
            <Plus className="size-4" />
            New category
          </Button>
        }
      />

      {loading ? (
        <Card>
          <div className="px-6 py-16 text-center text-sm text-ink-muted">
            Loading local database…
          </div>
        </Card>
      ) : categories.length === 0 ? (
        <Card>
          <EmptyState
            icon={<Tags className="size-8" />}
            title="No categories yet"
            description="Create categories such as Laptops, Networking, or Peripherals to organise your inventory."
            action={
              <Button variant="primary" onClick={openCreate}>
                <Plus className="size-4" />
                New category
              </Button>
            }
          />
        </Card>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {categories.map((category) => {
            const fields = category.customFields?.fields ?? [];
            const inUse = usageByCategory.get(category.id) ?? 0;
            return (
              <Card key={category.id} className="flex flex-col p-4">
                <div className="flex items-start justify-between gap-2">
                  <h2 className="text-sm font-semibold text-ink">
                    {category.name}
                  </h2>
                  <div className="-mt-1 -mr-1 flex shrink-0">
                    <button
                      type="button"
                      aria-label={`Edit ${category.name}`}
                      onClick={() => openEdit(category)}
                      className="rounded-md p-1.5 text-ink-muted hover:bg-surface-sunken hover:text-ink"
                    >
                      <Pencil className="size-4" />
                    </button>
                    <button
                      type="button"
                      aria-label={`Delete ${category.name}`}
                      onClick={() => setPendingDelete(category)}
                      className="rounded-md p-1.5 text-ink-muted hover:bg-surface-sunken hover:text-red-600"
                    >
                      <Trash2 className="size-4" />
                    </button>
                  </div>
                </div>

                {category.description ? (
                  <p className="mt-1 line-clamp-2 text-xs text-ink-muted">
                    {category.description}
                  </p>
                ) : null}

                <div className="mt-3 flex items-center gap-1.5 text-xs text-ink-muted">
                  <CalendarClock className="size-3.5" />
                  Default loan: {category.defaultLoanDays} day
                  {category.defaultLoanDays === 1 ? "" : "s"}
                </div>

                <div className="mt-3 flex flex-wrap gap-1">
                  {fields.length === 0 ? (
                    <span className="text-xs text-ink-muted/70">
                      No custom fields
                    </span>
                  ) : (
                    fields.map((field) => (
                      <span
                        key={field.key}
                        className="rounded bg-surface-sunken px-1.5 py-0.5 font-mono text-[10px] text-ink-muted"
                      >
                        {field.key}
                      </span>
                    ))
                  )}
                </div>

                <div className="mt-auto pt-3 text-xs text-ink-muted">
                  {inUse} item{inUse === 1 ? "" : "s"} assigned
                </div>
              </Card>
            );
          })}
        </div>
      )}

      <Modal
        open={form !== null}
        onClose={() => setForm(null)}
        size="lg"
        title={form?.id ? "Edit category" : "New category"}
        description="Custom fields become the specification inputs shown when registering equipment in this category."
        footer={
          <>
            <Button onClick={() => setForm(null)} disabled={saving}>
              Cancel
            </Button>
            <Button variant="primary" onClick={() => void save()} disabled={saving}>
              {saving ? "Saving…" : "Save category"}
            </Button>
          </>
        }
      >
        {form ? (
          <div className="space-y-4">
            {error ? (
              <p className="rounded-lg bg-red-500/10 px-3 py-2 text-xs text-red-700">
                {error}
              </p>
            ) : null}

            <div className="grid gap-4 sm:grid-cols-3">
              <div className="sm:col-span-2">
                <Field label="Name" required>
                  <Input
                    value={form.name}
                    placeholder="Laptops"
                    onChange={(e) =>
                      setForm({ ...form, name: e.target.value })
                    }
                  />
                </Field>
              </div>
              <Field label="Default loan (days)" hint="Used to auto-fill due dates">
                <Input
                  type="number"
                  min={0}
                  max={365}
                  value={form.defaultLoanDays}
                  onChange={(e) =>
                    setForm({
                      ...form,
                      defaultLoanDays: Number(e.target.value),
                    })
                  }
                />
              </Field>
            </div>

            <Field label="Description">
              <Textarea
                rows={2}
                value={form.description}
                placeholder="Portable computers issued to staff and students."
                onChange={(e) =>
                  setForm({ ...form, description: e.target.value })
                }
              />
            </Field>

            <CustomFieldsBuilder
              fields={form.fields}
              onChange={(fields) => setForm({ ...form, fields })}
            />
          </div>
        ) : null}
      </Modal>

      <ConfirmDialog
        open={pendingDelete !== null}
        destructive
        title="Delete category"
        confirmLabel="Delete"
        message={
          pendingDelete
            ? `Soft-delete "${pendingDelete.name}"? Equipment already assigned keeps its reference, and the deletion syncs to the server.`
            : ""
        }
        onCancel={() => setPendingDelete(null)}
        onConfirm={() => void confirmDelete()}
      />
    </>
  );
}
