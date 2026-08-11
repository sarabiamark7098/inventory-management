"use client";

import {
  EMPTY_CUSTOM_FIELDS,
  type BorrowingStatus,
  type BorrowingTransaction,
  type Category,
  type Equipment,
  type EquipmentCondition,
  type EquipmentStatus,
} from "@/lib/domain/types";
import { calculateExpectedReturn } from "@/lib/domain/loan";
import { nowIso, uuid } from "@/lib/utils/id";
import { getDatabase } from "./database";
import { enqueue } from "./outbox";

/**
 * All writes go through here so that the local document and its outbox entry
 * are always created together (US-4.1). Nothing in the UI talks to the network.
 */

/* ------------------------------------------------------------------ */
/* Categories                                                          */
/* ------------------------------------------------------------------ */

export type CategoryInput = Pick<
  Category,
  "name" | "description" | "customFields" | "defaultLoanDays"
>;

export async function createCategory(input: CategoryInput): Promise<Category> {
  const db = await getDatabase();
  const ts = nowIso();
  const doc: Category = {
    id: uuid(),
    name: input.name.trim(),
    description: input.description?.trim() || null,
    customFields: input.customFields ?? EMPTY_CUSTOM_FIELDS,
    defaultLoanDays: input.defaultLoanDays,
    createdAt: ts,
    updatedAt: ts,
    deletedAt: null,
  };
  await db.categories.insert(doc);
  await enqueue({
    table: "categories",
    action: "INSERT",
    docId: doc.id,
    payload: doc as unknown as Record<string, unknown>,
  });
  return doc;
}

export async function updateCategory(
  id: string,
  patch: Partial<CategoryInput>,
): Promise<void> {
  const db = await getDatabase();
  const doc = await db.categories.findOne(id).exec();
  if (!doc) throw new Error(`Category ${id} not found`);

  const next = {
    ...(patch.name !== undefined ? { name: patch.name.trim() } : {}),
    ...(patch.description !== undefined
      ? { description: patch.description?.trim() || null }
      : {}),
    ...(patch.customFields !== undefined
      ? { customFields: patch.customFields }
      : {}),
    ...(patch.defaultLoanDays !== undefined
      ? { defaultLoanDays: patch.defaultLoanDays }
      : {}),
    updatedAt: nowIso(),
  };

  await doc.incrementalPatch(next);
  const after = await db.categories.findOne(id).exec();
  await enqueue({
    table: "categories",
    action: "UPDATE",
    docId: id,
    payload: (after?.toJSON() ?? next) as unknown as Record<string, unknown>,
  });
}

/** Soft delete — the row survives so other clients learn about the removal. */
export async function deleteCategory(id: string): Promise<void> {
  const db = await getDatabase();
  const doc = await db.categories.findOne(id).exec();
  if (!doc) return;
  const ts = nowIso();
  await doc.incrementalPatch({ deletedAt: ts, updatedAt: ts });
  await enqueue({
    table: "categories",
    action: "DELETE",
    docId: id,
    payload: { id, deletedAt: ts, updatedAt: ts },
  });
}

/* ------------------------------------------------------------------ */
/* Equipment                                                           */
/* ------------------------------------------------------------------ */

export type EquipmentInput = Pick<
  Equipment,
  | "assetTag"
  | "serialNumber"
  | "categoryId"
  | "modelName"
  | "brand"
  | "status"
  | "condition"
  | "location"
  | "specifications"
>;

export async function createEquipment(
  input: EquipmentInput,
): Promise<Equipment> {
  const db = await getDatabase();

  const assetTag = input.assetTag.trim();
  const existing = await db.equipment
    .findOne({ selector: { assetTag, deletedAt: null } })
    .exec();
  if (existing) {
    throw new Error(`Asset tag "${assetTag}" is already in use.`);
  }

  const ts = nowIso();
  const doc: Equipment = {
    id: uuid(),
    assetTag,
    serialNumber: input.serialNumber?.trim() || null,
    categoryId: input.categoryId || null,
    modelName: input.modelName.trim(),
    brand: input.brand?.trim() || null,
    status: input.status,
    condition: input.condition,
    location: input.location?.trim() || null,
    specifications: input.specifications ?? {},
    createdAt: ts,
    updatedAt: ts,
    deletedAt: null,
  };
  await db.equipment.insert(doc);
  await enqueue({
    table: "equipment",
    action: "INSERT",
    docId: doc.id,
    payload: doc as unknown as Record<string, unknown>,
  });
  return doc;
}

export async function updateEquipment(
  id: string,
  patch: Partial<EquipmentInput>,
): Promise<void> {
  const db = await getDatabase();
  const doc = await db.equipment.findOne(id).exec();
  if (!doc) throw new Error(`Equipment ${id} not found`);

  const trimmed: Record<string, unknown> = { updatedAt: nowIso() };
  if (patch.assetTag !== undefined) trimmed.assetTag = patch.assetTag.trim();
  if (patch.serialNumber !== undefined)
    trimmed.serialNumber = patch.serialNumber?.trim() || null;
  if (patch.categoryId !== undefined)
    trimmed.categoryId = patch.categoryId || null;
  if (patch.modelName !== undefined) trimmed.modelName = patch.modelName.trim();
  if (patch.brand !== undefined) trimmed.brand = patch.brand?.trim() || null;
  if (patch.status !== undefined) trimmed.status = patch.status;
  if (patch.condition !== undefined) trimmed.condition = patch.condition;
  if (patch.location !== undefined)
    trimmed.location = patch.location?.trim() || null;
  if (patch.specifications !== undefined)
    trimmed.specifications = patch.specifications;

  await doc.incrementalPatch(trimmed);
  const after = await db.equipment.findOne(id).exec();
  await enqueue({
    table: "equipment",
    action: "UPDATE",
    docId: id,
    payload: (after?.toJSON() ?? trimmed) as unknown as Record<string, unknown>,
  });
}

export async function setEquipmentStatus(
  id: string,
  status: EquipmentStatus,
  condition?: EquipmentCondition,
): Promise<void> {
  await updateEquipment(id, {
    status,
    ...(condition ? { condition } : {}),
  });
}

export async function deleteEquipment(id: string): Promise<void> {
  const db = await getDatabase();
  const doc = await db.equipment.findOne(id).exec();
  if (!doc) return;
  const ts = nowIso();
  await doc.incrementalPatch({ deletedAt: ts, updatedAt: ts });
  await enqueue({
    table: "equipment",
    action: "DELETE",
    docId: id,
    payload: { id, deletedAt: ts, updatedAt: ts },
  });
}

/** Asset-tag / serial lookup used by the QR + barcode scanner. */
export async function findEquipmentByCode(
  code: string,
): Promise<Equipment | null> {
  const db = await getDatabase();
  const trimmed = code.trim();
  if (!trimmed) return null;

  const byTag = await db.equipment
    .findOne({ selector: { assetTag: trimmed, deletedAt: null } })
    .exec();
  if (byTag) return byTag.toJSON() as Equipment;

  const bySerial = await db.equipment
    .findOne({ selector: { serialNumber: trimmed, deletedAt: null } })
    .exec();
  if (bySerial) return bySerial.toJSON() as Equipment;

  // QR payloads are minted as `ICT:<uuid>` by the label generator.
  const id = trimmed.startsWith("ICT:") ? trimmed.slice(4) : trimmed;
  const byId = await db.equipment.findOne(id).exec();
  return byId && !byId.deletedAt ? (byId.toJSON() as Equipment) : null;
}

/* ------------------------------------------------------------------ */
/* Borrowing                                                           */
/* ------------------------------------------------------------------ */

export interface CheckoutInput {
  equipmentId: string;
  borrowerName: string;
  borrowerEmail: string;
  issueDate?: string;
  /** Overrides the category default when supplied. */
  loanDays?: number;
  expectedReturnDate?: string;
  notes?: string | null;
  status?: BorrowingStatus;
}

/**
 * Issues a loan: writes the transaction, flips the asset to BORROWED, and
 * queues both mutations. Local validation is best-effort — the server has the
 * final say via the Status Lock Rule.
 */
export async function checkoutEquipment(
  input: CheckoutInput,
): Promise<BorrowingTransaction> {
  const db = await getDatabase();

  const equipmentDoc = await db.equipment.findOne(input.equipmentId).exec();
  if (!equipmentDoc || equipmentDoc.deletedAt) {
    throw new Error("Equipment not found.");
  }
  const equipment = equipmentDoc.toJSON() as Equipment;
  if (equipment.status !== "AVAILABLE") {
    throw new Error(
      `${equipment.assetTag} is currently ${equipment.status.toLowerCase()} and cannot be issued.`,
    );
  }

  let loanDays = input.loanDays;
  if (loanDays === undefined && equipment.categoryId) {
    const category = await db.categories.findOne(equipment.categoryId).exec();
    loanDays = category?.defaultLoanDays;
  }

  const issueDate = input.issueDate ?? nowIso();
  const expectedReturnDate =
    input.expectedReturnDate ?? calculateExpectedReturn(issueDate, loanDays ?? 7);

  const ts = nowIso();
  const tx: BorrowingTransaction = {
    id: uuid(),
    equipmentId: equipment.id,
    borrowerName: input.borrowerName.trim(),
    borrowerEmail: input.borrowerEmail.trim(),
    issueDate,
    expectedReturnDate,
    actualReturnDate: null,
    status: input.status ?? "ISSUED",
    notes: input.notes?.trim() || null,
    conflictReason: null,
    createdAt: ts,
    updatedAt: ts,
    deletedAt: null,
  };

  await db.borrowings.insert(tx);
  await enqueue({
    table: "borrowings",
    action: "INSERT",
    docId: tx.id,
    payload: tx as unknown as Record<string, unknown>,
  });

  await setEquipmentStatus(equipment.id, "BORROWED");
  return tx;
}

export interface ReturnInput {
  transactionId: string;
  condition: EquipmentCondition;
  /** Damage / missing-accessory notes appended to the transaction (US-3.3). */
  inspectionNotes?: string | null;
  markLost?: boolean;
  actualReturnDate?: string;
}

export async function returnEquipment(input: ReturnInput): Promise<void> {
  const db = await getDatabase();
  const txDoc = await db.borrowings.findOne(input.transactionId).exec();
  if (!txDoc) throw new Error("Borrowing transaction not found.");

  const returnedAt = input.actualReturnDate ?? nowIso();
  const ts = nowIso();

  const existingNotes = txDoc.notes;
  const inspection = input.inspectionNotes?.trim();
  const notes = inspection
    ? [existingNotes, `[Return inspection] ${inspection}`]
        .filter(Boolean)
        .join("\n")
    : existingNotes;

  await txDoc.incrementalPatch({
    status: "RETURNED" as const,
    actualReturnDate: returnedAt,
    notes,
    updatedAt: ts,
  });

  const after = await db.borrowings.findOne(input.transactionId).exec();
  await enqueue({
    table: "borrowings",
    action: "UPDATE",
    docId: input.transactionId,
    payload: after!.toJSON() as unknown as Record<string, unknown>,
  });

  if (txDoc.equipmentId) {
    // A device returned as DAMAGED goes straight to the maintenance bench.
    const nextStatus: EquipmentStatus = input.markLost
      ? "LOST"
      : input.condition === "DAMAGED" || input.condition === "POOR"
        ? "MAINTENANCE"
        : "AVAILABLE";
    await updateEquipment(txDoc.equipmentId, {
      status: nextStatus,
      condition: input.condition,
    });
  }
}

export async function setBorrowingStatus(
  id: string,
  status: BorrowingStatus,
): Promise<void> {
  const db = await getDatabase();
  const doc = await db.borrowings.findOne(id).exec();
  if (!doc) throw new Error("Borrowing transaction not found.");
  await doc.incrementalPatch({ status, updatedAt: nowIso() });
  const after = await db.borrowings.findOne(id).exec();
  await enqueue({
    table: "borrowings",
    action: "UPDATE",
    docId: id,
    payload: after!.toJSON() as unknown as Record<string, unknown>,
  });
}

/** Approves a pending request and issues the device in one step. */
export async function approveRequest(id: string): Promise<void> {
  const db = await getDatabase();
  const doc = await db.borrowings.findOne(id).exec();
  if (!doc) throw new Error("Borrowing request not found.");

  await setBorrowingStatus(id, "ISSUED");
  if (doc.equipmentId) {
    await setEquipmentStatus(doc.equipmentId, "BORROWED");
  }
}

export async function requestLoan(
  input: CheckoutInput,
): Promise<BorrowingTransaction> {
  const db = await getDatabase();
  const equipmentDoc = await db.equipment.findOne(input.equipmentId).exec();
  if (!equipmentDoc) throw new Error("Equipment not found.");

  let loanDays = input.loanDays;
  if (loanDays === undefined && equipmentDoc.categoryId) {
    const category = await db.categories.findOne(equipmentDoc.categoryId).exec();
    loanDays = category?.defaultLoanDays;
  }

  const issueDate = input.issueDate ?? nowIso();
  const ts = nowIso();
  const tx: BorrowingTransaction = {
    id: uuid(),
    equipmentId: input.equipmentId,
    borrowerName: input.borrowerName.trim(),
    borrowerEmail: input.borrowerEmail.trim(),
    issueDate,
    expectedReturnDate:
      input.expectedReturnDate ??
      calculateExpectedReturn(issueDate, loanDays ?? 7),
    actualReturnDate: null,
    status: "REQUESTED",
    notes: input.notes?.trim() || null,
    conflictReason: null,
    createdAt: ts,
    updatedAt: ts,
    deletedAt: null,
  };

  await db.borrowings.insert(tx);
  await enqueue({
    table: "borrowings",
    action: "INSERT",
    docId: tx.id,
    payload: tx as unknown as Record<string, unknown>,
  });
  return tx;
}
