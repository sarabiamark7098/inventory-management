/**
 * Validates the RxDB collection schemas without a browser.
 *
 * Runs the same dev-mode + ajv validation stack the client uses, but against the
 * in-memory RxStorage, so schema mistakes surface in CI instead of at runtime.
 *
 *   npm run check:schemas
 */
import { addRxPlugin, createRxDatabase } from "rxdb";
import { RxDBDevModePlugin, disableWarnings } from "rxdb/plugins/dev-mode";
import { wrappedValidateAjvStorage } from "rxdb/plugins/validate-ajv";
import { getRxStorageMemory } from "rxdb/plugins/storage-memory";

import {
  borrowingSchema,
  categorySchema,
  equipmentSchema,
  syncQueueSchema,
} from "../src/lib/db/schemas.ts";

disableWarnings();
addRxPlugin(RxDBDevModePlugin);

const now = new Date().toISOString();

const db = await createRxDatabase({
  name: "schema_check",
  storage: wrappedValidateAjvStorage({ storage: getRxStorageMemory() }),
});

await db.addCollections({
  categories: { schema: categorySchema as never },
  equipment: { schema: equipmentSchema as never },
  borrowings: { schema: borrowingSchema as never },
  sync_queue: { schema: syncQueueSchema as never },
});
console.log("✓ collections created");

await db.categories.insert({
  id: "11111111-1111-4111-8111-111111111111",
  name: "Laptops",
  description: null,
  customFields: {
    fields: [
      {
        key: "ram",
        label: "RAM",
        type: "select",
        required: true,
        options: ["8GB", "16GB"],
      },
    ],
  },
  defaultLoanDays: 14,
  createdAt: now,
  updatedAt: now,
  deletedAt: null,
} as never);

await db.equipment.insert({
  id: "22222222-2222-4222-8222-222222222222",
  assetTag: "ICT-0001",
  serialNumber: "SN-1",
  categoryId: "11111111-1111-4111-8111-111111111111",
  modelName: "ThinkPad T14",
  brand: "Lenovo",
  status: "AVAILABLE",
  condition: "GOOD",
  location: "Room 101",
  specifications: { ram: "16GB", nested: { a: 1 } },
  createdAt: now,
  updatedAt: now,
  deletedAt: null,
} as never);

await db.borrowings.insert({
  id: "33333333-3333-4333-8333-333333333333",
  equipmentId: "22222222-2222-4222-8222-222222222222",
  borrowerName: "Ada Lovelace",
  borrowerEmail: "ada@example.com",
  issueDate: now,
  expectedReturnDate: now,
  actualReturnDate: null,
  status: "ISSUED",
  notes: null,
  conflictReason: null,
  createdAt: now,
  updatedAt: now,
  deletedAt: null,
} as never);

await db.sync_queue.insert({
  id: "44444444-4444-4444-8444-444444444444",
  table: "equipment",
  action: "INSERT",
  docId: "22222222-2222-4222-8222-222222222222",
  payload: { freeForm: true, deep: { x: [1, 2] } },
  clientTimestamp: now,
  status: "PENDING",
  attempts: 0,
  lastError: null,
} as never);
console.log("✓ inserts accepted by ajv validation");

const byTag = await db.equipment
  .findOne({ selector: { assetTag: "ICT-0001", deletedAt: null } })
  .exec();
if (!byTag) throw new Error("indexed assetTag lookup returned nothing");
console.log("✓ indexed lookup:", byTag.get("modelName"));

const queue = await db.sync_queue
  .find({
    selector: { status: { $in: ["PENDING", "FAILED"] } },
    sort: [{ clientTimestamp: "asc" }],
  })
  .exec();
console.log("✓ outbox drain query:", queue.length, "entry");

await db.close();
console.log("\nALL SCHEMA CHECKS PASSED");
