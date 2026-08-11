"use client";

import { X } from "lucide-react";
import { useEffect, type ReactNode } from "react";

import { cx } from "./primitives";

export function Modal({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  size = "md",
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: string;
  children: ReactNode;
  footer?: ReactNode;
  size?: "md" | "lg";
}) {
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKey);
    const { overflow } = document.body.style;
    document.body.style.overflow = "hidden";
    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = overflow;
    };
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-black/40 p-0 sm:items-center sm:p-6">
      <button
        type="button"
        aria-label="Close dialog"
        className="absolute inset-0 cursor-default"
        onClick={onClose}
      />
      <div
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className={cx(
          "card relative z-10 my-auto w-full overflow-hidden shadow-xl",
          size === "lg" ? "max-w-3xl" : "max-w-lg",
        )}
      >
        <header className="flex items-start justify-between gap-4 border-b border-border-subtle px-5 py-4">
          <div>
            <h2 className="text-base font-semibold text-ink">{title}</h2>
            {description ? (
              <p className="mt-0.5 text-sm text-ink-muted">{description}</p>
            ) : null}
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close"
            className="-mr-1 rounded-md p-1 text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
          >
            <X className="size-4" />
          </button>
        </header>

        <div className="max-h-[65vh] overflow-y-auto px-5 py-4">{children}</div>

        {footer ? (
          <footer className="flex flex-wrap items-center justify-end gap-2 border-t border-border-subtle bg-surface-muted px-5 py-3">
            {footer}
          </footer>
        ) : null}
      </div>
    </div>
  );
}

export function ConfirmDialog({
  open,
  title,
  message,
  confirmLabel = "Confirm",
  destructive,
  onConfirm,
  onCancel,
}: {
  open: boolean;
  title: string;
  message: string;
  confirmLabel?: string;
  destructive?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}) {
  return (
    <Modal open={open} onClose={onCancel} title={title}>
      <p className="text-sm text-ink-muted">{message}</p>
      <div className="mt-5 flex justify-end gap-2">
        <button
          type="button"
          onClick={onCancel}
          className="h-9.5 rounded-lg border border-border-subtle px-3.5 text-sm font-medium text-ink transition-colors hover:bg-surface-sunken"
        >
          Cancel
        </button>
        <button
          type="button"
          onClick={onConfirm}
          className={cx(
            "h-9.5 rounded-lg px-3.5 text-sm font-medium text-white transition-colors",
            destructive
              ? "bg-red-600 hover:bg-red-700"
              : "bg-brand hover:bg-brand-strong",
          )}
        >
          {confirmLabel}
        </button>
      </div>
    </Modal>
  );
}
