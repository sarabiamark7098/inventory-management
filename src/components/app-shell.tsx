"use client";

import {
  ArrowLeftRight,
  LayoutDashboard,
  Laptop,
  Menu,
  Tags,
  X,
} from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";

import { startSyncEngine } from "@/lib/db/sync-engine";
import { SyncStatusBadge } from "./sync-status-badge";
import { cx } from "./ui/primitives";

const NAV = [
  { href: "/", label: "Dashboard", icon: LayoutDashboard },
  { href: "/equipment", label: "Equipment", icon: Laptop },
  { href: "/categories", label: "Categories", icon: Tags },
  { href: "/borrowing", label: "Borrowing", icon: ArrowLeftRight },
] as const;

export function AppShell({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const [mobileOpen, setMobileOpen] = useState(false);

  // One background sync engine per browser session, started after hydration.
  useEffect(() => startSyncEngine(), []);

  return (
    <div className="flex min-h-full flex-col">
      <header className="sticky top-0 z-30 border-b border-border-subtle bg-surface/90 backdrop-blur">
        <div className="mx-auto flex h-14 max-w-7xl items-center gap-3 px-4">
          <button
            type="button"
            className="rounded-md p-1.5 text-ink-muted hover:bg-surface-sunken md:hidden"
            aria-label={mobileOpen ? "Close navigation" : "Open navigation"}
            onClick={() => setMobileOpen((v) => !v)}
          >
            {mobileOpen ? <X className="size-5" /> : <Menu className="size-5" />}
          </button>

          <Link href="/" className="flex items-center gap-2">
            <span className="grid size-7 place-items-center rounded-md bg-brand text-xs font-bold text-white">
              ICT
            </span>
            <span className="hidden text-sm font-semibold tracking-tight text-ink sm:block">
              Equipment &amp; Borrowing
            </span>
          </Link>

          <nav className="ml-4 hidden items-center gap-1 md:flex">
            {NAV.map((item) => (
              <NavLink key={item.href} {...item} pathname={pathname} />
            ))}
          </nav>


          <div className="ml-auto">
            <SyncStatusBadge />
          </div>
        </div>

        {mobileOpen ? (
          <nav className="flex flex-col gap-1 border-t border-border-subtle px-3 py-2 md:hidden">
            {NAV.map((item) => (
              <NavLink
                key={item.href}
                {...item}
                pathname={pathname}
                onNavigate={() => setMobileOpen(false)}
              />
            ))}
          </nav>
        ) : null}
      </header>

      <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6">
        {children}
      </main>
    </div>
  );
}

function NavLink({
  href,
  label,
  icon: Icon,
  pathname,
  onNavigate,
}: {
  href: string;
  label: string;
  icon: typeof LayoutDashboard;
  pathname: string;
  onNavigate?: () => void;
}) {
  const active = href === "/" ? pathname === "/" : pathname.startsWith(href);
  return (
    <Link
      href={href}
      onClick={onNavigate}
      aria-current={active ? "page" : undefined}
      className={cx(
        "flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors",
        active
          ? "bg-brand-soft text-brand"
          : "text-ink-muted hover:bg-surface-sunken hover:text-ink",
      )}
    >
      <Icon className="size-4" />
      {label}
    </Link>
  );
}
