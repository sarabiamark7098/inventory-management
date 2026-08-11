/**
 * Shared domain contract.
 *
 * These types are the single source of truth for both the RxDB client store and
 * the Prisma/PostgreSQL server store. Field names use camelCase everywhere; the
 * Prisma schema maps them onto the snake_case SQL columns from CLAUDE.md.
 */

export const EQUIPMENT_STATUSES = [
  "AVAILABLE",
  "BORROWED",
  "MAINTENANCE",
  "DECOMMISSIONED",
  "LOST",
] as const;
export type EquipmentStatus = (typeof EQUIPMENT_STATUSES)[number];

export const EQUIPMENT_CONDITIONS = [
  "NEW",
  "GOOD",
  "FAIR",
  "POOR",
  "DAMAGED",
] as const;
export type EquipmentCondition = (typeof EQUIPMENT_CONDITIONS)[number];

export const BORROWING_STATUSES = [
  "REQUESTED",
  "APPROVED",
  "ISSUED",
  "RETURNED",
  "OVERDUE",
  "REJECTED",
  "CANCELLED",
  "CONFLICT_PENDING_REVIEW",
] as const;
export type BorrowingStatus = (typeof BORROWING_STATUSES)[number];

/** Loan states that physically hold a device and therefore need server validation. */
export const ACTIVE_LOAN_STATUSES: BorrowingStatus[] = [
  "APPROVED",
  "ISSUED",
  "OVERDUE",
];

/**
 * Legal equipment status transitions (US-2.3). A transition to the same status is
 * always allowed so idempotent writes from the sync engine never trip validation.
 */
export const EQUIPMENT_STATUS_TRANSITIONS: Record<
  EquipmentStatus,
  EquipmentStatus[]
> = {
  AVAILABLE: ["BORROWED", "MAINTENANCE", "DECOMMISSIONED", "LOST"],
  BORROWED: ["AVAILABLE", "MAINTENANCE", "LOST"],
  MAINTENANCE: ["AVAILABLE", "DECOMMISSIONED", "LOST"],
  DECOMMISSIONED: ["AVAILABLE"],
  LOST: ["AVAILABLE", "MAINTENANCE", "DECOMMISSIONED"],
};

export function canTransitionEquipment(
  from: EquipmentStatus,
  to: EquipmentStatus,
): boolean {
  if (from === to) return true;
  return EQUIPMENT_STATUS_TRANSITIONS[from]?.includes(to) ?? false;
}

/* ------------------------------------------------------------------ */
/* Dynamic category metadata (US-1.2)                                  */
/* ------------------------------------------------------------------ */

export const CUSTOM_FIELD_TYPES = [
  "text",
  "number",
  "boolean",
  "date",
  "select",
] as const;
export type CustomFieldType = (typeof CUSTOM_FIELD_TYPES)[number];

export interface CustomFieldDefinition {
  /** Stable machine key used as the property name inside `Equipment.specifications`. */
  key: string;
  label: string;
  type: CustomFieldType;
  required: boolean;
  /** Only meaningful when `type === "select"`. */
  options?: string[];
  placeholder?: string;
}

/** Shape stored in `categories.custom_fields` JSONB. */
export interface CategoryCustomFields {
  fields: CustomFieldDefinition[];
}

export const EMPTY_CUSTOM_FIELDS: CategoryCustomFields = { fields: [] };

/* ------------------------------------------------------------------ */
/* Entities                                                            */
/* ------------------------------------------------------------------ */

/** Fields every synced entity carries. Timestamps are ISO-8601 UTC strings. */
export interface SyncedEntity {
  id: string;
  createdAt: string;
  updatedAt: string;
  deletedAt: string | null;
}

export interface Category extends SyncedEntity {
  name: string;
  description: string | null;
  customFields: CategoryCustomFields;
  defaultLoanDays: number;
}

export interface Equipment extends SyncedEntity {
  assetTag: string;
  serialNumber: string | null;
  categoryId: string | null;
  modelName: string;
  brand: string | null;
  status: EquipmentStatus;
  condition: EquipmentCondition;
  location: string | null;
  specifications: Record<string, unknown>;
}

export interface BorrowingTransaction extends SyncedEntity {
  equipmentId: string | null;
  borrowerName: string;
  borrowerEmail: string;
  issueDate: string;
  expectedReturnDate: string;
  actualReturnDate: string | null;
  status: BorrowingStatus;
  notes: string | null;
  /** Set by the server conflict engine when a dual checkout is detected. */
  conflictReason: string | null;
}

export type EntityType = "categories" | "equipment" | "borrowings";

export const ENTITY_TYPES: EntityType[] = [
  "categories",
  "equipment",
  "borrowings",
];

/* ------------------------------------------------------------------ */
/* Outbox / sync protocol                                              */
/* ------------------------------------------------------------------ */

export type SyncOperation = "INSERT" | "UPDATE" | "DELETE";

export type SyncQueueStatus = "PENDING" | "IN_FLIGHT" | "FAILED";

export interface SyncQueueEntry {
  id: string;
  /** Entity collection the mutation targets. */
  table: EntityType;
  action: SyncOperation;
  /** Primary key of the mutated document. */
  docId: string;
  payload: Record<string, unknown>;
  clientTimestamp: string;
  status: SyncQueueStatus;
  attempts: number;
  lastError: string | null;
}

/** One mutation as sent to `POST /api/sync/push`. */
export interface SyncPushChange {
  id: string;
  table: EntityType;
  action: SyncOperation;
  docId: string;
  payload: Record<string, unknown>;
  clientTimestamp: string;
}

export interface SyncPushRequest {
  clientId: string;
  changes: SyncPushChange[];
}

export type SyncResultStatus =
  | "APPLIED"
  | "IGNORED_STALE"
  | "CONFLICT"
  | "REJECTED";

export interface SyncPushResult {
  /** Echoes `SyncPushChange.id` so the client can dequeue precisely. */
  changeId: string;
  table: EntityType;
  docId: string;
  status: SyncResultStatus;
  message?: string;
  /** Authoritative server state after the write, echoed back for local reconciliation. */
  document?: Category | Equipment | BorrowingTransaction | null;
}

export interface SyncPushResponse {
  serverTime: string;
  results: SyncPushResult[];
}

export interface SyncPullResponse {
  serverTime: string;
  categories: Category[];
  equipment: Equipment[];
  borrowings: BorrowingTransaction[];
}

export type ConnectionState = "ONLINE" | "OFFLINE" | "SYNCING" | "ERROR";

export interface SyncStatusSnapshot {
  connection: ConnectionState;
  pending: number;
  failed: number;
  lastSyncedAt: string | null;
  lastError: string | null;
}
