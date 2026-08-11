"use client";

import {
  AlertTriangle,
  Cloud,
  CloudOff,
  RefreshCw,
  UploadCloud,
} from "lucide-react";
import { useEffect, useState } from "react";

import type { SyncStatusSnapshot } from "@/lib/domain/types";
import { retryFailed, syncStatus$, synchronize } from "@/lib/db/sync-engine";
import { cx } from "./ui/primitives";

/** Live connectivity + outbox indicator (US-4.4). */
export function useSyncStatus(): SyncStatusSnapshot {
  const [status, setStatus] = useState<SyncStatusSnapshot>(() =>
    syncStatus$.getValue(),
  );
  useEffect(() => {
    const sub = syncStatus$.subscribe(setStatus);
    return () => sub.unsubscribe();
  }, []);
  return status;
}

function describe(status: SyncStatusSnapshot): {
  label: string;
  tone: string;
  icon: React.ReactNode;
  spin?: boolean;
} {
  if (status.connection === "SYNCING") {
    return {
      label: "Syncing",
      tone: "bg-blue-500/10 text-blue-700 ring-blue-500/25",
      icon: <RefreshCw className="size-3.5" />,
      spin: true,
    };
  }
  if (status.connection === "OFFLINE") {
    return {
      label: status.pending > 0 ? `Offline · ${status.pending} queued` : "Offline",
      tone: "bg-surface-sunken text-ink-muted ring-border-subtle",
      icon: <CloudOff className="size-3.5" />,
    };
  }
  // Red is reserved for changes the server actively refused — those need a
  // human. A server that is merely unreachable still shows the pending count,
  // because the queue is draining on its own.
  if (status.failed > 0) {
    return {
      label: `Sync error [${status.failed}]`,
      tone: "bg-red-500/10 text-red-700 ring-red-500/25",
      icon: <AlertTriangle className="size-3.5" />,
    };
  }
  if (status.pending > 0) {
    return {
      label: `Sync pending [${status.pending}]`,
      tone: "bg-amber-500/10 text-amber-700 ring-amber-500/25",
      icon: <UploadCloud className="size-3.5" />,
    };
  }
  if (status.connection === "ERROR") {
    return {
      label: "Sync error",
      tone: "bg-red-500/10 text-red-700 ring-red-500/25",
      icon: <AlertTriangle className="size-3.5" />,
    };
  }
  return {
    label: "Online",
    tone: "bg-emerald-500/10 text-emerald-700 ring-emerald-500/25",
    icon: <Cloud className="size-3.5" />,
  };
}

export function SyncStatusBadge() {
  const status = useSyncStatus();
  const view = describe(status);

  const title = [
    status.lastSyncedAt
      ? `Last synced ${new Date(status.lastSyncedAt).toLocaleString()}`
      : "Never synced",
    status.lastError ? `Error: ${status.lastError}` : null,
    "Click to sync now",
  ]
    .filter(Boolean)
    .join("\n");

  return (
    <button
      type="button"
      title={title}
      onClick={() => void (status.failed > 0 ? retryFailed() : synchronize())}
      className={cx(
        "inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition-opacity hover:opacity-80",
        view.tone,
      )}
    >
      <span className={view.spin ? "animate-spin" : undefined}>{view.icon}</span>
      {view.label}
    </button>
  );
}
