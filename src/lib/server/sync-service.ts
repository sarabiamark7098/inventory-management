import { Prisma } from "@/generated/prisma/client";

import {
  ACTIVE_LOAN_STATUSES,
  BORROWING_STATUSES,
  EQUIPMENT_CONDITIONS,
  EQUIPMENT_STATUSES,
  type BorrowingStatus,
  type EquipmentCondition,
  type EquipmentStatus,
  type SyncPullResponse,
  type SyncPushChange,
  type SyncPushRequest,
  type SyncPushResponse,
  type SyncPushResult,
} from "@/lib/domain/types";
import { incomingCheckoutWins } from "@/lib/domain/loan";
import { prisma } from "./prisma";
import {
  asRecord,
  date,
  normalizeCustomFields,
  num,
  requiredDate,
  requiredStr,
  str,
  toBorrowingDto,
  toCategoryDto,
  toEquipmentDto,
} from "./mappers";

const UUID_RE =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

type Tx = Prisma.TransactionClient;

/* ------------------------------------------------------------------ */
/* Request validation                                                  */
/* ------------------------------------------------------------------ */

export class SyncRequestError extends Error {}

export function parsePushRequest(body: unknown): SyncPushRequest {
  const root = asRecord(body);
  const changes = root.changes;
  if (!Array.isArray(changes)) {
    throw new SyncRequestError("`changes` must be an array.");
  }
  if (changes.length > 500) {
    throw new SyncRequestError("Too many changes in one push (max 500).");
  }

  return {
    clientId: requiredStr(root.clientId, "unknown"),
    changes: changes.map((raw, index) => {
      const change = asRecord(raw);
      const table = change.table;
      const action = change.action;

      if (
        table !== "categories" &&
        table !== "equipment" &&
        table !== "borrowings"
      ) {
        throw new SyncRequestError(`changes[${index}].table is invalid.`);
      }
      if (action !== "INSERT" && action !== "UPDATE" && action !== "DELETE") {
        throw new SyncRequestError(`changes[${index}].action is invalid.`);
      }
      const docId = str(change.docId);
      if (!docId || !UUID_RE.test(docId)) {
        throw new SyncRequestError(`changes[${index}].docId must be a UUID.`);
      }

      return {
        id: requiredStr(change.id, docId),
        table,
        action,
        docId,
        payload: asRecord(change.payload),
        clientTimestamp:
          date(change.clientTimestamp)?.toISOString() ??
          new Date().toISOString(),
      } satisfies SyncPushChange;
    }),
  };
}

function oneOf<T extends string>(
  value: unknown,
  allowed: readonly T[],
  fallback: T,
): T {
  return typeof value === "string" && (allowed as readonly string[]).includes(value)
    ? (value as T)
    : fallback;
}

/* ------------------------------------------------------------------ */
/* Push                                                                */
/* ------------------------------------------------------------------ */

/**
 * Prisma 7 leaves `.message` empty on known request errors — the readable text
 * is assembled by its logger, not the error object. Fall back to the error name
 * plus its code so the client's outbox records something actionable.
 */
function describeError(err: unknown): string {
  if (err instanceof Error) {
    const code = (err as { code?: string }).code;
    const base = err.message.trim() || err.name;
    return code ? `${base} (${code})` : base;
  }
  return String(err);
}

/**
 * Distinguishes "the database is unreachable" from "this change is invalid".
 * The former must fail the whole request so the client retries the batch later;
 * only the latter is a per-change REJECTED verdict.
 */
function isInfrastructureError(err: unknown): boolean {
  if (!err || typeof err !== "object") return false;
  const { name, code } = err as { name?: string; code?: string };
  if (
    name === "PrismaClientInitializationError" ||
    name === "PrismaClientRustPanicError"
  ) {
    return true;
  }
  if (typeof code !== "string") return false;
  // P1xxx are Prisma's connection-level codes; the rest are raw socket errors.
  return (
    code.startsWith("P1") ||
    ["ECONNREFUSED", "ECONNRESET", "ETIMEDOUT", "ENOTFOUND", "EPIPE"].includes(
      code,
    )
  );
}

export async function handlePush(
  request: SyncPushRequest,
): Promise<SyncPushResponse> {
  // Replay in the order the operator made the edits, not the order they arrived.
  const ordered = [...request.changes].sort((a, b) =>
    a.clientTimestamp.localeCompare(b.clientTimestamp),
  );

  const results: SyncPushResult[] = [];
  for (const change of ordered) {
    try {
      results.push(await applyChange(change, request.clientId));
    } catch (err) {
      if (isInfrastructureError(err)) throw err;
      results.push({
        changeId: change.id,
        table: change.table,
        docId: change.docId,
        status: "REJECTED",
        message: describeError(err),
      });
    }
  }

  return { serverTime: new Date().toISOString(), results };
}

function applyChange(
  change: SyncPushChange,
  clientId: string,
): Promise<SyncPushResult> {
  return prisma.$transaction(async (tx) => {
    const result =
      change.table === "categories"
        ? await applyCategory(tx, change)
        : change.table === "equipment"
          ? await applyEquipment(tx, change)
          : await applyBorrowing(tx, change);

    if (result.status === "APPLIED" || result.status === "CONFLICT") {
      await tx.syncChange.create({
        data: {
          entityType: change.table,
          entityId: change.docId,
          operation: change.action,
          data: change.payload as Prisma.InputJsonValue,
          clientId,
          clientTimestamp: new Date(change.clientTimestamp),
        },
      });
    }
    return result;
  });
}

/**
 * Last-Write-Wins guard shared by every entity: a mutation whose `updatedAt` is
 * not newer than the stored row is dropped and the server state is echoed back.
 */
function isStale(incoming: Date, stored: Date): boolean {
  return incoming.getTime() <= stored.getTime();
}

function payloadUpdatedAt(change: SyncPushChange): Date {
  return requiredDate(
    change.payload.updatedAt,
    new Date(change.clientTimestamp),
  );
}

/* ---------------------------- categories --------------------------- */

async function applyCategory(
  tx: Tx,
  change: SyncPushChange,
): Promise<SyncPushResult> {
  const { docId, payload } = change;
  const existing = await tx.category.findUnique({ where: { id: docId } });
  const updatedAt = payloadUpdatedAt(change);

  if (existing && isStale(updatedAt, existing.updatedAt)) {
    return {
      changeId: change.id,
      table: "categories",
      docId,
      status: "IGNORED_STALE",
      document: toCategoryDto(existing),
    };
  }

  if (change.action === "DELETE") {
    if (!existing) {
      return skipMissing(change);
    }
    const row = await tx.category.update({
      where: { id: docId },
      data: { deletedAt: date(payload.deletedAt) ?? new Date(), updatedAt },
    });
    return applied(change, toCategoryDto(row));
  }

  const data = {
    name: requiredStr(payload.name, existing?.name ?? "Untitled"),
    description: str(payload.description),
    customFields: normalizeCustomFields(
      payload.customFields,
    ) as unknown as Prisma.InputJsonValue,
    defaultLoanDays: num(payload.defaultLoanDays, existing?.defaultLoanDays ?? 7),
    deletedAt: date(payload.deletedAt),
    updatedAt,
  };

  const row = await tx.category.upsert({
    where: { id: docId },
    create: {
      id: docId,
      ...data,
      createdAt: requiredDate(payload.createdAt, updatedAt),
    },
    update: data,
  });
  return applied(change, toCategoryDto(row));
}

/* ---------------------------- equipment ---------------------------- */

async function applyEquipment(
  tx: Tx,
  change: SyncPushChange,
): Promise<SyncPushResult> {
  const { docId, payload } = change;
  const existing = await tx.equipment.findUnique({ where: { id: docId } });
  const updatedAt = payloadUpdatedAt(change);

  if (existing && isStale(updatedAt, existing.updatedAt)) {
    return {
      changeId: change.id,
      table: "equipment",
      docId,
      status: "IGNORED_STALE",
      document: toEquipmentDto(existing),
    };
  }

  if (change.action === "DELETE") {
    if (!existing) return skipMissing(change);
    const row = await tx.equipment.update({
      where: { id: docId },
      data: { deletedAt: date(payload.deletedAt) ?? new Date(), updatedAt },
    });
    return applied(change, toEquipmentDto(row));
  }

  const assetTag = requiredStr(payload.assetTag, existing?.assetTag ?? docId);

  // asset_tag is UNIQUE; a different row already holding it is a hard reject so
  // the client surfaces it instead of silently losing the record.
  const tagOwner = await tx.equipment.findUnique({ where: { assetTag } });
  if (tagOwner && tagOwner.id !== docId) {
    return {
      changeId: change.id,
      table: "equipment",
      docId,
      status: "REJECTED",
      message: `Asset tag "${assetTag}" already belongs to another record.`,
      document: toEquipmentDto(tagOwner),
    };
  }

  const categoryId = str(payload.categoryId);
  const data = {
    assetTag,
    serialNumber: str(payload.serialNumber),
    categoryId:
      categoryId && (await tx.category.count({ where: { id: categoryId } })) > 0
        ? categoryId
        : null,
    modelName: requiredStr(payload.modelName, existing?.modelName ?? "Unknown"),
    brand: str(payload.brand),
    status: oneOf<EquipmentStatus>(
      payload.status,
      EQUIPMENT_STATUSES,
      (existing?.status as EquipmentStatus) ?? "AVAILABLE",
    ),
    condition: oneOf<EquipmentCondition>(
      payload.condition,
      EQUIPMENT_CONDITIONS,
      (existing?.condition as EquipmentCondition) ?? "GOOD",
    ),
    location: str(payload.location),
    specifications: asRecord(payload.specifications) as Prisma.InputJsonValue,
    deletedAt: date(payload.deletedAt),
    updatedAt,
  };

  const row = await tx.equipment.upsert({
    where: { id: docId },
    create: {
      id: docId,
      ...data,
      createdAt: requiredDate(payload.createdAt, updatedAt),
    },
    update: data,
  });
  return applied(change, toEquipmentDto(row));
}

/* ---------------------------- borrowings --------------------------- */

async function applyBorrowing(
  tx: Tx,
  change: SyncPushChange,
): Promise<SyncPushResult> {
  const { docId, payload } = change;
  const existing = await tx.borrowingTransaction.findUnique({
    where: { id: docId },
  });
  const updatedAt = payloadUpdatedAt(change);

  if (existing && isStale(updatedAt, existing.updatedAt)) {
    return {
      changeId: change.id,
      table: "borrowings",
      docId,
      status: "IGNORED_STALE",
      document: toBorrowingDto(existing),
    };
  }

  if (change.action === "DELETE") {
    if (!existing) return skipMissing(change);
    const row = await tx.borrowingTransaction.update({
      where: { id: docId },
      data: { deletedAt: date(payload.deletedAt) ?? new Date(), updatedAt },
    });
    await reconcileEquipmentStatus(tx, row.equipmentId, updatedAt);
    return applied(change, toBorrowingDto(row));
  }

  const equipmentId = str(payload.equipmentId);
  const issueDate = requiredDate(
    payload.issueDate,
    existing?.issueDate ?? new Date(),
  );
  const expectedReturnDate = requiredDate(
    payload.expectedReturnDate,
    existing?.expectedReturnDate ?? issueDate,
  );
  const createdAt = requiredDate(
    payload.createdAt,
    existing?.createdAt ?? updatedAt,
  );
  let status = oneOf<BorrowingStatus>(
    payload.status,
    BORROWING_STATUSES,
    (existing?.status as BorrowingStatus) ?? "REQUESTED",
  );
  let conflictReason = str(payload.conflictReason);
  let conflicted = false;

  /* Status Lock Rule: a loan that physically holds the device must not overlap
   * another active loan on the same asset. Offline clients cannot see each
   * other, so the server adjudicates here. */
  if (equipmentId && ACTIVE_LOAN_STATUSES.includes(status)) {
    const rival = await tx.borrowingTransaction.findFirst({
      where: {
        equipmentId,
        id: { not: docId },
        deletedAt: null,
        status: { in: ACTIVE_LOAN_STATUSES },
        issueDate: { lte: expectedReturnDate },
        expectedReturnDate: { gte: issueDate },
      },
      orderBy: [{ createdAt: "asc" }, { id: "asc" }],
    });

    if (rival) {
      conflicted = true;
      // Deterministic winner: the checkout that physically happened first.
      // Ties break on id so both clients reach the same verdict.
      if (incomingCheckoutWins({ id: docId, createdAt }, rival)) {
        await tx.borrowingTransaction.update({
          where: { id: rival.id },
          data: {
            status: "CONFLICT_PENDING_REVIEW",
            conflictReason: `Overlapping checkout: transaction ${docId} claimed this asset first.`,
            updatedAt,
          },
        });
      } else {
        status = "CONFLICT_PENDING_REVIEW";
        conflictReason = `Overlapping checkout: transaction ${rival.id} already holds this asset from ${rival.issueDate.toISOString()} to ${rival.expectedReturnDate.toISOString()}.`;
      }
    }
  }

  const data = {
    equipmentId:
      equipmentId &&
      (await tx.equipment.count({ where: { id: equipmentId } })) > 0
        ? equipmentId
        : null,
    borrowerName: requiredStr(
      payload.borrowerName,
      existing?.borrowerName ?? "Unknown",
    ),
    borrowerEmail: requiredStr(
      payload.borrowerEmail,
      existing?.borrowerEmail ?? "unknown@example.invalid",
    ),
    issueDate,
    expectedReturnDate,
    actualReturnDate: date(payload.actualReturnDate),
    status,
    notes: str(payload.notes),
    conflictReason,
    deletedAt: date(payload.deletedAt),
    updatedAt,
  };

  const row = await tx.borrowingTransaction.upsert({
    where: { id: docId },
    create: { id: docId, ...data, createdAt },
    update: data,
  });

  await reconcileEquipmentStatus(tx, row.equipmentId, updatedAt);

  return {
    changeId: change.id,
    table: "borrowings",
    docId,
    status: conflicted ? "CONFLICT" : "APPLIED",
    message: conflicted ? (conflictReason ?? "Overlapping checkout") : undefined,
    document: toBorrowingDto(row),
  };
}

/**
 * Derives the asset's physical availability from the authoritative loan rows.
 * MAINTENANCE / DECOMMISSIONED / LOST are operator decisions and stay untouched.
 */
async function reconcileEquipmentStatus(
  tx: Tx,
  equipmentId: string | null,
  updatedAt: Date,
): Promise<void> {
  if (!equipmentId) return;

  const equipment = await tx.equipment.findUnique({
    where: { id: equipmentId },
  });
  if (!equipment) return;
  if (equipment.status !== "AVAILABLE" && equipment.status !== "BORROWED") {
    return;
  }

  const activeLoans = await tx.borrowingTransaction.count({
    where: {
      equipmentId,
      deletedAt: null,
      status: { in: ACTIVE_LOAN_STATUSES },
    },
  });

  const desired: EquipmentStatus = activeLoans > 0 ? "BORROWED" : "AVAILABLE";
  if (desired === equipment.status) return;

  await tx.equipment.update({
    where: { id: equipmentId },
    data: { status: desired, updatedAt },
  });
}

function applied(
  change: SyncPushChange,
  document: SyncPushResult["document"],
): SyncPushResult {
  return {
    changeId: change.id,
    table: change.table,
    docId: change.docId,
    status: "APPLIED",
    document,
  };
}

/** A DELETE for a row the server never saw: nothing to do, but stop retrying. */
function skipMissing(change: SyncPushChange): SyncPushResult {
  return {
    changeId: change.id,
    table: change.table,
    docId: change.docId,
    status: "APPLIED",
    document: null,
  };
}

/* ------------------------------------------------------------------ */
/* Pull                                                                */
/* ------------------------------------------------------------------ */

export async function handlePull(since: Date | null): Promise<SyncPullResponse> {
  // Captured before the reads so a row written mid-query is re-sent next time
  // rather than skipped.
  const serverTime = new Date().toISOString();
  const where = since ? { updatedAt: { gt: since } } : {};

  const [categories, equipment, borrowings] = await Promise.all([
    prisma.category.findMany({ where, orderBy: { updatedAt: "asc" } }),
    prisma.equipment.findMany({ where, orderBy: { updatedAt: "asc" } }),
    prisma.borrowingTransaction.findMany({
      where,
      orderBy: { updatedAt: "asc" },
    }),
  ]);

  return {
    serverTime,
    categories: categories.map(toCategoryDto),
    equipment: equipment.map(toEquipmentDto),
    borrowings: borrowings.map(toBorrowingDto),
  };
}
