"use client";

import {
  addRxPlugin,
  createRxDatabase,
  type RxCollection,
  type RxDatabase,
} from "rxdb";
import { RxDBQueryBuilderPlugin } from "rxdb/plugins/query-builder";
import { RxDBUpdatePlugin } from "rxdb/plugins/update";
import { RxDBLeaderElectionPlugin } from "rxdb/plugins/leader-election";
import { getRxStorageDexie } from "rxdb/plugins/storage-dexie";

import type {
  BorrowingTransaction,
  Category,
  Equipment,
  SyncQueueEntry,
} from "@/lib/domain/types";
import {
  borrowingSchema,
  categorySchema,
  equipmentSchema,
  syncQueueSchema,
} from "./schemas";

export const DB_NAME = "ict_inventory";

export type Collections = {
  categories: RxCollection<Category>;
  equipment: RxCollection<Equipment>;
  borrowings: RxCollection<BorrowingTransaction>;
  sync_queue: RxCollection<SyncQueueEntry>;
};

export type AppDatabase = RxDatabase<Collections>;

let pluginsRegistered = false;

async function registerPlugins(): Promise<void> {
  if (pluginsRegistered) return;
  pluginsRegistered = true;

  addRxPlugin(RxDBQueryBuilderPlugin);
  addRxPlugin(RxDBUpdatePlugin);
  // Elects a single tab to own the sync loop so N open tabs don't all push.
  addRxPlugin(RxDBLeaderElectionPlugin);

  if (process.env.NODE_ENV === "development") {
    const { RxDBDevModePlugin, disableWarnings } = await import(
      "rxdb/plugins/dev-mode"
    );
    disableWarnings();
    addRxPlugin(RxDBDevModePlugin);
  }
}

async function buildStorage() {
  const storage = getRxStorageDexie();
  if (process.env.NODE_ENV === "development") {
    // dev-mode does not validate documents on its own; ajv catches bad writes early.
    const { wrappedValidateAjvStorage } = await import(
      "rxdb/plugins/validate-ajv"
    );
    return wrappedValidateAjvStorage({ storage });
  }
  return storage;
}

/**
 * Single database promise per browser session. Held on `globalThis` so Next.js
 * fast-refresh reuses the open IndexedDB handle instead of throwing DB6.
 */
const globalForDb = globalThis as unknown as {
  __ictDbPromise?: Promise<AppDatabase>;
};

async function create(): Promise<AppDatabase> {
  await registerPlugins();

  const db = await createRxDatabase<Collections>({
    name: DB_NAME,
    storage: await buildStorage(),
    multiInstance: true,
    eventReduce: true,
    ignoreDuplicate: process.env.NODE_ENV === "development",
  });

  await db.addCollections({
    categories: { schema: categorySchema },
    equipment: { schema: equipmentSchema },
    borrowings: { schema: borrowingSchema },
    sync_queue: { schema: syncQueueSchema },
  });

  if (process.env.NODE_ENV === "development") {
    // Handy for inspecting the local store and outbox from the devtools console.
    (globalThis as unknown as { __ictDb?: AppDatabase }).__ictDb = db;
  }

  return db;
}

/** Lazily opens (and memoises) the local RxDB/IndexedDB database. Browser only. */
export function getDatabase(): Promise<AppDatabase> {
  if (typeof window === "undefined") {
    return Promise.reject(
      new Error("getDatabase() is browser-only: RxDB needs IndexedDB."),
    );
  }
  if (!globalForDb.__ictDbPromise) {
    globalForDb.__ictDbPromise = create().catch((err) => {
      // Let a later call retry instead of caching a rejected promise forever.
      globalForDb.__ictDbPromise = undefined;
      throw err;
    });
  }
  return globalForDb.__ictDbPromise;
}
