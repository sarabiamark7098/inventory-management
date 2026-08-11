"use client";

import {
  Download,
  Laptop,
  Pencil,
  Plus,
  QrCode,
  ScanLine,
  Search,
  Trash2,
  X,
} from "lucide-react";
import { useMemo, useState } from "react";

import { CodeScanner } from "@/components/equipment/code-scanner";
import {
  EquipmentFormModal,
  SpecificationList,
} from "@/components/equipment/equipment-form";
import { QrLabelSheet } from "@/components/equipment/qr-label";
import { ConfirmDialog, Modal } from "@/components/ui/modal";
import {
  Button,
  Card,
  EmptyState,
  Input,
  PageHeader,
  Select,
} from "@/components/ui/primitives";
import {
  ConditionBadge,
  EquipmentStatusBadge,
} from "@/components/ui/status-badge";
import { useCategoryMap, useEquipment } from "@/lib/db/hooks";
import { deleteEquipment, findEquipmentByCode } from "@/lib/db/repositories";
import {
  EQUIPMENT_CONDITIONS,
  EQUIPMENT_STATUSES,
  type Equipment,
} from "@/lib/domain/types";
import { downloadCsv, timestampedFilename, toCsv } from "@/lib/utils/csv";

export default function EquipmentPage() {
  const { data: equipment, loading } = useEquipment();
  const categories = useCategoryMap();

  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("");
  const [conditionFilter, setConditionFilter] = useState("");

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Equipment | null>(null);
  const [scanOpen, setScanOpen] = useState(false);
  const [scanResult, setScanResult] = useState<Equipment | null>(null);
  const [scanMiss, setScanMiss] = useState<string | null>(null);
  const [labelsFor, setLabelsFor] = useState<Equipment[] | null>(null);
  const [pendingDelete, setPendingDelete] = useState<Equipment | null>(null);

  /** All filtering happens against the local RxDB result set — works offline. */
  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase();
    return equipment.filter((item) => {
      if (statusFilter && item.status !== statusFilter) return false;
      if (conditionFilter && item.condition !== conditionFilter) return false;
      if (categoryFilter) {
        if (categoryFilter === "__none__") {
          if (item.categoryId) return false;
        } else if (item.categoryId !== categoryFilter) {
          return false;
        }
      }
      if (!term) return true;

      const haystack = [
        item.assetTag,
        item.serialNumber,
        item.modelName,
        item.brand,
        item.location,
        categories.get(item.categoryId ?? "")?.name,
        ...Object.values(item.specifications ?? {}).map((v) => String(v ?? "")),
      ]
        .filter(Boolean)
        .join(" ")
        .toLowerCase();
      return haystack.includes(term);
    });
  }, [equipment, search, statusFilter, categoryFilter, conditionFilter, categories]);

  const filtersActive =
    Boolean(search) || Boolean(statusFilter) || Boolean(categoryFilter) || Boolean(conditionFilter);

  function clearFilters() {
    setSearch("");
    setStatusFilter("");
    setCategoryFilter("");
    setConditionFilter("");
  }

  function exportCsv() {
    const csv = toCsv(filtered, [
      { header: "Asset Tag", value: (r) => r.assetTag },
      { header: "Serial Number", value: (r) => r.serialNumber },
      { header: "Model", value: (r) => r.modelName },
      { header: "Brand", value: (r) => r.brand },
      {
        header: "Category",
        value: (r) => categories.get(r.categoryId ?? "")?.name ?? "",
      },
      { header: "Status", value: (r) => r.status },
      { header: "Condition", value: (r) => r.condition },
      { header: "Location", value: (r) => r.location },
      { header: "Specifications", value: (r) => r.specifications },
      { header: "Updated At", value: (r) => r.updatedAt },
    ]);
    downloadCsv(timestampedFilename("ict-equipment"), csv);
  }

  async function handleScan(code: string) {
    const match = await findEquipmentByCode(code);
    if (match) {
      setScanResult(match);
      setScanMiss(null);
      setScanOpen(false);
    } else {
      setScanMiss(code);
    }
  }

  return (
    <>
      <PageHeader
        title="Equipment"
        description="Register, audit, and track ICT hardware. Search and export work fully offline against the local store."
        actions={
          <>
            <Button onClick={() => setScanOpen(true)}>
              <ScanLine className="size-4" />
              Scan
            </Button>
            <Button onClick={exportCsv} disabled={filtered.length === 0}>
              <Download className="size-4" />
              Export CSV
            </Button>
            <Button
              onClick={() => setLabelsFor(filtered)}
              disabled={filtered.length === 0}
            >
              <QrCode className="size-4" />
              Labels
            </Button>
            <Button
              variant="primary"
              onClick={() => {
                setEditing(null);
                setFormOpen(true);
              }}
            >
              <Plus className="size-4" />
              Register
            </Button>
          </>
        }
      />

      <Card className="mb-4 p-3">
        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
          <div className="relative sm:col-span-2 lg:col-span-1">
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-muted" />
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search tag, serial, model, specs…"
              className="pl-9"
              aria-label="Search equipment"
            />
          </div>

          <Select
            value={categoryFilter}
            onChange={(e) => setCategoryFilter(e.target.value)}
            aria-label="Filter by category"
          >
            <option value="">All categories</option>
            <option value="__none__">Uncategorised</option>
            {[...categories.values()].map((category) => (
              <option key={category.id} value={category.id}>
                {category.name}
              </option>
            ))}
          </Select>

          <Select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            aria-label="Filter by status"
          >
            <option value="">All statuses</option>
            {EQUIPMENT_STATUSES.map((status) => (
              <option key={status} value={status}>
                {status}
              </option>
            ))}
          </Select>

          <Select
            value={conditionFilter}
            onChange={(e) => setConditionFilter(e.target.value)}
            aria-label="Filter by condition"
          >
            <option value="">All conditions</option>
            {EQUIPMENT_CONDITIONS.map((condition) => (
              <option key={condition} value={condition}>
                {condition}
              </option>
            ))}
          </Select>
        </div>

        {filtersActive ? (
          <div className="mt-2 flex items-center gap-2 text-xs text-ink-muted">
            <span>
              {filtered.length} of {equipment.length} items
            </span>
            <button
              type="button"
              onClick={clearFilters}
              className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-surface-sunken hover:text-ink"
            >
              <X className="size-3" />
              Clear filters
            </button>
          </div>
        ) : null}
      </Card>

      <Card className="overflow-hidden">
        {loading ? (
          <div className="px-6 py-16 text-center text-sm text-ink-muted">
            Loading local database…
          </div>
        ) : filtered.length === 0 ? (
          <EmptyState
            icon={<Laptop className="size-8" />}
            title={
              equipment.length === 0
                ? "No equipment registered"
                : "No matches for these filters"
            }
            description={
              equipment.length === 0
                ? "Register your first device to start tracking assignments and loans."
                : "Try a different search term or clear the filters."
            }
            action={
              equipment.length === 0 ? (
                <Button
                  variant="primary"
                  onClick={() => {
                    setEditing(null);
                    setFormOpen(true);
                  }}
                >
                  <Plus className="size-4" />
                  Register equipment
                </Button>
              ) : (
                <Button onClick={clearFilters}>Clear filters</Button>
              )
            }
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border-subtle bg-surface-muted text-xs text-ink-muted">
                <tr>
                  <th className="px-4 py-2.5 font-medium">Asset</th>
                  <th className="px-4 py-2.5 font-medium">Category</th>
                  <th className="px-4 py-2.5 font-medium">Status</th>
                  <th className="px-4 py-2.5 font-medium">Condition</th>
                  <th className="px-4 py-2.5 font-medium">Location</th>
                  <th className="px-4 py-2.5 text-right font-medium">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border-subtle">
                {filtered.map((item) => (
                  <tr key={item.id} className="hover:bg-surface-muted/60">
                    <td className="px-4 py-2.5">
                      <span className="block font-mono text-xs font-semibold text-ink">
                        {item.assetTag}
                      </span>
                      <span className="block text-xs text-ink-muted">
                        {item.brand ? `${item.brand} ` : ""}
                        {item.modelName}
                      </span>
                    </td>
                    <td className="px-4 py-2.5 text-xs text-ink-muted">
                      {categories.get(item.categoryId ?? "")?.name ?? "—"}
                    </td>
                    <td className="px-4 py-2.5">
                      <EquipmentStatusBadge status={item.status} />
                    </td>
                    <td className="px-4 py-2.5">
                      <ConditionBadge condition={item.condition} />
                    </td>
                    <td className="px-4 py-2.5 text-xs text-ink-muted">
                      {item.location ?? "—"}
                    </td>
                    <td className="px-4 py-2.5">
                      <div className="flex justify-end gap-0.5">
                        <RowAction
                          label="QR label"
                          onClick={() => setLabelsFor([item])}
                        >
                          <QrCode className="size-4" />
                        </RowAction>
                        <RowAction
                          label="Edit"
                          onClick={() => {
                            setEditing(item);
                            setFormOpen(true);
                          }}
                        >
                          <Pencil className="size-4" />
                        </RowAction>
                        <RowAction
                          label="Delete"
                          onClick={() => setPendingDelete(item)}
                        >
                          <Trash2 className="size-4 text-red-500" />
                        </RowAction>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <EquipmentFormModal
        open={formOpen}
        equipment={editing}
        onClose={() => setFormOpen(false)}
      />

      <Modal
        open={scanOpen}
        onClose={() => {
          setScanOpen(false);
          setScanMiss(null);
        }}
        title="Scan asset"
        description="Point the camera at a QR label, use a USB scanner, or type the code."
      >
        <CodeScanner onDetected={(code) => void handleScan(code)} />
        {scanMiss ? (
          <p className="mt-3 rounded-lg bg-amber-500/10 px-3 py-2 text-xs text-amber-700">
            No equipment matches “{scanMiss}”.
          </p>
        ) : null}
      </Modal>

      <Modal
        open={scanResult !== null}
        onClose={() => setScanResult(null)}
        title={scanResult ? scanResult.assetTag : ""}
        description={scanResult?.modelName}
        footer={
          scanResult ? (
            <Button
              variant="primary"
              onClick={() => {
                setEditing(scanResult);
                setScanResult(null);
                setFormOpen(true);
              }}
            >
              <Pencil className="size-4" />
              Edit asset
            </Button>
          ) : null
        }
      >
        {scanResult ? (
          <div className="space-y-3">
            <div className="flex flex-wrap gap-2">
              <EquipmentStatusBadge status={scanResult.status} />
              <ConditionBadge condition={scanResult.condition} />
            </div>
            <dl className="grid gap-x-4 gap-y-1 text-xs sm:grid-cols-2">
              <Row label="Serial" value={scanResult.serialNumber ?? "—"} />
              <Row
                label="Category"
                value={categories.get(scanResult.categoryId ?? "")?.name ?? "—"}
              />
              <Row label="Location" value={scanResult.location ?? "—"} />
              <Row label="Brand" value={scanResult.brand ?? "—"} />
            </dl>
            <div className="border-t border-border-subtle pt-3">
              <SpecificationList specifications={scanResult.specifications} />
            </div>
          </div>
        ) : null}
      </Modal>

      <Modal
        open={labelsFor !== null}
        onClose={() => setLabelsFor(null)}
        size="lg"
        title="QR labels"
        description="Print and affix these to the physical assets for fast audits."
      >
        {labelsFor ? <QrLabelSheet items={labelsFor} /> : null}
      </Modal>

      <ConfirmDialog
        open={pendingDelete !== null}
        destructive
        title="Delete equipment"
        confirmLabel="Delete"
        message={
          pendingDelete
            ? `Soft-delete ${pendingDelete.assetTag} (${pendingDelete.modelName})? The removal syncs to the server on the next cycle.`
            : ""
        }
        onCancel={() => setPendingDelete(null)}
        onConfirm={() => {
          if (pendingDelete) void deleteEquipment(pendingDelete.id);
          setPendingDelete(null);
        }}
      />
    </>
  );
}

function RowAction({
  label,
  onClick,
  children,
}: {
  label: string;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      onClick={onClick}
      className="rounded-md p-1.5 text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
    >
      {children}
    </button>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-2">
      <dt className="text-ink-muted">{label}</dt>
      <dd className="text-right font-medium text-ink">{value}</dd>
    </div>
  );
}
