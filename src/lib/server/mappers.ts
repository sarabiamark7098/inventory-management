import type {
  BorrowingTransaction as PrismaBorrowing,
  Category as PrismaCategory,
  Equipment as PrismaEquipment,
} from "@/generated/prisma/client";

import {
  EMPTY_CUSTOM_FIELDS,
  type BorrowingStatus,
  type BorrowingTransaction,
  type Category,
  type CategoryCustomFields,
  type Equipment,
  type EquipmentCondition,
  type EquipmentStatus,
} from "@/lib/domain/types";

/** Prisma rows carry `Date` objects and `JsonValue`s; the wire format is ISO + typed JSON. */

function iso(value: Date | null | undefined): string | null {
  return value ? value.toISOString() : null;
}

export function toCategoryDto(row: PrismaCategory): Category {
  return {
    id: row.id,
    name: row.name,
    description: row.description,
    customFields: normalizeCustomFields(row.customFields),
    defaultLoanDays: row.defaultLoanDays,
    createdAt: row.createdAt.toISOString(),
    updatedAt: row.updatedAt.toISOString(),
    deletedAt: iso(row.deletedAt),
  };
}

export function toEquipmentDto(row: PrismaEquipment): Equipment {
  return {
    id: row.id,
    assetTag: row.assetTag,
    serialNumber: row.serialNumber,
    categoryId: row.categoryId,
    modelName: row.modelName,
    brand: row.brand,
    status: row.status as EquipmentStatus,
    condition: row.condition as EquipmentCondition,
    location: row.location,
    specifications: asRecord(row.specifications),
    createdAt: row.createdAt.toISOString(),
    updatedAt: row.updatedAt.toISOString(),
    deletedAt: iso(row.deletedAt),
  };
}

export function toBorrowingDto(row: PrismaBorrowing): BorrowingTransaction {
  return {
    id: row.id,
    equipmentId: row.equipmentId,
    borrowerName: row.borrowerName,
    borrowerEmail: row.borrowerEmail,
    issueDate: row.issueDate.toISOString(),
    expectedReturnDate: row.expectedReturnDate.toISOString(),
    actualReturnDate: iso(row.actualReturnDate),
    status: row.status as BorrowingStatus,
    notes: row.notes,
    conflictReason: row.conflictReason,
    createdAt: row.createdAt.toISOString(),
    updatedAt: row.updatedAt.toISOString(),
    deletedAt: iso(row.deletedAt),
  };
}

export function asRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === "object" && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {};
}

export function normalizeCustomFields(value: unknown): CategoryCustomFields {
  const record = asRecord(value);
  return Array.isArray(record.fields)
    ? { fields: record.fields as CategoryCustomFields["fields"] }
    : EMPTY_CUSTOM_FIELDS;
}

/* ------------------------------------------------------------------ */
/* Payload coercion — client payloads are untrusted JSON               */
/* ------------------------------------------------------------------ */

export function str(value: unknown): string | null {
  if (typeof value !== "string") return null;
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

export function requiredStr(value: unknown, fallback: string): string {
  return str(value) ?? fallback;
}

export function num(value: unknown, fallback: number): number {
  const parsed = typeof value === "number" ? value : Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

export function date(value: unknown): Date | null {
  if (typeof value !== "string" && !(value instanceof Date)) return null;
  const parsed = value instanceof Date ? value : new Date(value);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

export function requiredDate(value: unknown, fallback: Date): Date {
  return date(value) ?? fallback;
}
