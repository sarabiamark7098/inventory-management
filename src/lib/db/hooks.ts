"use client";

import { useEffect, useMemo, useState } from "react";
import type { MangoQuery, RxCollection } from "rxdb";

import type {
  BorrowingTransaction,
  Category,
  Equipment,
} from "@/lib/domain/types";
import { getDatabase, type AppDatabase, type Collections } from "./database";

/** Resolves the singleton RxDB handle once the component mounts in the browser. */
export function useDatabase(): {
  db: AppDatabase | null;
  error: Error | null;
} {
  const [db, setDb] = useState<AppDatabase | null>(null);
  const [error, setError] = useState<Error | null>(null);

  useEffect(() => {
    let cancelled = false;
    getDatabase()
      .then((instance) => {
        if (!cancelled) setDb(instance);
      })
      .catch((err: Error) => {
        if (!cancelled) setError(err);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  return { db, error };
}

/**
 * Subscribes to a live RxDB query. Results re-render automatically on local
 * writes and on documents arriving from the pull phase.
 */
export function useRxQuery<K extends keyof Collections, T>(
  collection: K,
  query: MangoQuery<T>,
): { data: T[]; loading: boolean; error: Error | null } {
  const { db, error: dbError } = useDatabase();
  const [data, setData] = useState<T[]>([]);
  const [loading, setLoading] = useState(true);
  const queryKey = JSON.stringify(query);

  useEffect(() => {
    if (!db) return;
    const col = db[collection] as unknown as RxCollection<T>;
    const sub = col.find(JSON.parse(queryKey) as MangoQuery<T>).$.subscribe({
      next: (docs) => {
        setData(docs.map((doc) => doc.toJSON() as T));
        setLoading(false);
      },
      error: () => setLoading(false),
    });
    return () => sub.unsubscribe();
  }, [db, collection, queryKey]);

  return { data, loading: loading && !dbError, error: dbError };
}

const NOT_DELETED = { deletedAt: null } as const;

export function useCategories() {
  return useRxQuery<"categories", Category>("categories", {
    selector: NOT_DELETED,
    sort: [{ name: "asc" }],
  });
}

export function useEquipment() {
  return useRxQuery<"equipment", Equipment>("equipment", {
    selector: NOT_DELETED,
    sort: [{ updatedAt: "desc" }],
  });
}

export function useBorrowings() {
  return useRxQuery<"borrowings", BorrowingTransaction>("borrowings", {
    selector: NOT_DELETED,
    sort: [{ updatedAt: "desc" }],
  });
}

/** Convenience map for rendering category names against equipment rows. */
export function useCategoryMap(): Map<string, Category> {
  const { data } = useCategories();
  return useMemo(() => new Map(data.map((c) => [c.id, c])), [data]);
}
