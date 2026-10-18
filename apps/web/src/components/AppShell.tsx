import Link from "next/link";
import type { ReactNode } from "react";
import { LogoutButton } from "@/components/LogoutButton";

export interface NavItem {
  href: string;
  label: string;
  active?: boolean;
}

/** Kerangka halaman setelah login: header (merek, nama konteks, keluar) dan navigasi. */
export function AppShell({
  context,
  nav = [],
  userLabel,
  children,
}: {
  context: string;
  nav?: NavItem[];
  userLabel?: string;
  children: ReactNode;
}) {
  return (
    <div className="flex flex-1 flex-col bg-zinc-50 dark:bg-zinc-950">
      <header className="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div className="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-3">
          <div>
            <p className="text-xs font-semibold tracking-wide text-emerald-700 dark:text-emerald-400">
              Sentra Absensi Sekolah
            </p>
            <p className="font-semibold text-zinc-900 dark:text-zinc-50">{context}</p>
          </div>
          <div className="flex items-center gap-3">
            {userLabel ? (
              <span className="hidden text-sm text-zinc-600 sm:inline dark:text-zinc-400">{userLabel}</span>
            ) : null}
            <Link
              href="/account/two-factor"
              className="rounded-lg px-2 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800"
            >
              Keamanan
            </Link>
            <LogoutButton />
          </div>
        </div>
        {nav.length > 0 ? (
          <nav aria-label="Navigasi utama" className="mx-auto flex w-full max-w-5xl gap-1 px-4">
            {nav.map((item) => (
              <Link
                key={item.href}
                href={item.href}
                aria-current={item.active ? "page" : undefined}
                className={`border-b-2 px-3 py-2 text-sm font-medium ${
                  item.active
                    ? "border-emerald-700 text-emerald-800 dark:border-emerald-400 dark:text-emerald-300"
                    : "border-transparent text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
                }`}
              >
                {item.label}
              </Link>
            ))}
          </nav>
        ) : null}
      </header>
      <main className="mx-auto w-full max-w-5xl flex-1 space-y-6 px-4 py-8">{children}</main>
    </div>
  );
}
