import type { RxJsonSchema } from "rxdb";

import type {
  BorrowingTransaction,
  Category,
  Equipment,
  SyncQueueEntry,
} from "@/lib/domain/types";

/** ISO-8601 timestamps are fixed width; RxDB needs a maxLength to build indexes. */
const ISO_TIMESTAMP = { type: "string", maxLength: 30 } as const;
const UUID = { type: "string", maxLength: 36 } as const;

export const categorySchema: RxJsonSchema<Category> = {
  title: "category",
  version: 0,
  primaryKey: "id",
  type: "object",
  properties: {
    id: UUID,
    name: { type: "string", maxLength: 255 },
    description: { type: ["string", "null"] },
    customFields: {
      type: "object",
      properties: {
        fields: {
          type: "array",
          items: {
            type: "object",
            properties: {
              key: { type: "string" },
              label: { type: "string" },
              type: { type: "string" },
              required: { type: "boolean" },
              options: { type: "array", items: { type: "string" } },
              placeholder: { type: "string" },
            },
            required: ["key", "label", "type", "required"],
          },
        },
      },
      required: ["fields"],
    },
    defaultLoanDays: { type: "number", minimum: 0, maximum: 3650 },
    createdAt: ISO_TIMESTAMP,
    updatedAt: ISO_TIMESTAMP,
    deletedAt: { type: ["string", "null"] },
  },
  required: [
    "id",
    "name",
    "customFields",
    "defaultLoanDays",
    "createdAt",
    "updatedAt",
  ],
  indexes: ["updatedAt"],
};

export const equipmentSchema: RxJsonSchema<Equipment> = {
  title: "equipment",
  version: 0,
  primaryKey: "id",
  type: "object",
  properties: {
    id: UUID,
    assetTag: { type: "string", maxLength: 100 },
    serialNumber: { type: ["string", "null"] },
    categoryId: { type: ["string", "null"], maxLength: 36 },
    modelName: { type: "string", maxLength: 255 },
    brand: { type: ["string", "null"] },
    status: { type: "string", maxLength: 20 },
    condition: { type: "string", maxLength: 20 },
    location: { type: ["string", "null"] },
    specifications: { type: "object", additionalProperties: true },
    createdAt: ISO_TIMESTAMP,
    updatedAt: ISO_TIMESTAMP,
    deletedAt: { type: ["string", "null"] },
  },
  required: [
    "id",
    "assetTag",
    "modelName",
    "status",
    "condition",
    "specifications",
    "createdAt",
    "updatedAt",
  ],
  indexes: ["assetTag", "status", "updatedAt"],
};

export const borrowingSchema: RxJsonSchema<BorrowingTransaction> = {
  title: "borrowing",
  version: 0,
  primaryKey: "id",
  type: "object",
  properties: {
    id: UUID,
    equipmentId: { type: ["string", "null"], maxLength: 36 },
    borrowerName: { type: "string", maxLength: 255 },
    borrowerEmail: { type: "string", maxLength: 255 },
    issueDate: ISO_TIMESTAMP,
    expectedReturnDate: ISO_TIMESTAMP,
    actualReturnDate: { type: ["string", "null"] },
    status: { type: "string", maxLength: 30 },
    notes: { type: ["string", "null"] },
    conflictReason: { type: ["string", "null"] },
    createdAt: ISO_TIMESTAMP,
    updatedAt: ISO_TIMESTAMP,
    deletedAt: { type: ["string", "null"] },
  },
  required: [
    "id",
    "borrowerName",
    "borrowerEmail",
    "issueDate",
    "expectedReturnDate",
    "status",
    "createdAt",
    "updatedAt",
  ],
  indexes: ["status", "expectedReturnDate", "updatedAt"],
};

/** Local-only outbox. Never replicated; it *drives* replication. */
export const syncQueueSchema: RxJsonSchema<SyncQueueEntry> = {
  title: "sync_queue",
  version: 0,
  primaryKey: "id",
  type: "object",
  properties: {
    id: UUID,
    table: { type: "string", maxLength: 20 },
    action: { type: "string", maxLength: 10 },
    docId: UUID,
    payload: { type: "object", additionalProperties: true },
    clientTimestamp: ISO_TIMESTAMP,
    status: { type: "string", maxLength: 12 },
    attempts: { type: "number", minimum: 0, maximum: 1000, multipleOf: 1 },
    lastError: { type: ["string", "null"] },
  },
  required: [
    "id",
    "table",
    "action",
    "docId",
    "payload",
    "clientTimestamp",
    "status",
    "attempts",
  ],
  indexes: ["clientTimestamp", "status"],
};
