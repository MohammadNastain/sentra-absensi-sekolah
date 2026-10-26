"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useRef, useState, type ComponentType, type ReactNode, type SVGProps } from "react";
import { api } from "@/lib/api";
import { IconClose, IconLogout, IconMenu, IconShield } from "@/components/icons";
import { ThemeToggle } from "@/components/ThemeToggle";

export interface NavItem {
  href: string;
  label: string;
  active?: boolean;
  icon?: ComponentType<SVGProps<SVGSVGElement>>;
  /** Judul kelompok; tampil saat kelompok berganti. */
  group?: string;
}

/** Lambang merek: perisai bertanda centang di atas kotak teal berlapis emas tipis. */
export function BrandMark({ size = 40 }: { size?: number }) {
  return (
    <span
      className="inline-flex items-center justify-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-700 text-white shadow-lg shadow-brand-950/30 ring-1 ring-white/20"
      style={{ width: size, height: size }}
    >
      <IconShield width={size * 0.55} height={size * 0.55} />
    </span>
  );
}

function initials(source: string): string {
  const parts = source.split(/[\s@._-]+/).filter(Boolean);
  return ((parts[0]?.[0] ?? "?") + (parts[1]?.[0] ?? "")).toUpperCase();
}

/**
 * Kerangka halaman setelah login. Dengan `nav`: sidebar teal berlekuk yang menyatu dengan area konten, bilah atas
 * dengan menu akun, dan laci geser di layar kecil. Tanpa `nav` (mis. Platform): hanya bilah atas.
 */
export function AppShell({
  context,
  nav = [],
  userLabel,
  userName,
  demo = false,
  actions,
  children,
}: {
  context: string;
  nav?: NavItem[];
  userLabel?: string;
  userName?: string;
  /** Akun demo bersama: menu keamanan akun disembunyikan (sandi/2FA tidak boleh diubah). */
  demo?: boolean;
  /** Tombol tambahan di bilah atas, sebelum pengalih tema (mis. lonceng notifikasi). */
  actions?: ReactNode;
  children: ReactNode;
}) {
  const pathname = usePathname();
  const [drawer, setDrawer] = useState(false);
  const hasSidebar = nav.length > 0;

  useEffect(() => {
    const id = requestAnimationFrame(() => setDrawer(false));
    return () => cancelAnimationFrame(id);
  }, [pathname]);

  return (
    <div className="flex min-h-dvh flex-1">
      {hasSidebar ? <Sidebar nav={nav} context={context} open={drawer} onClose={() => setDrawer(false)} /> : null}
      <div className="flex min-w-0 flex-1 flex-col">
        <TopBar context={context} userLabel={userLabel} userName={userName} demo={demo} actions={actions} onMenu={hasSidebar ? () => setDrawer(true) : undefined} />
        <main key={pathname} className="mx-auto w-full max-w-[1280px] flex-1 animate-rise space-y-6 px-4 pb-12 pt-2 sm:px-8">
          {children}
        </main>
      </div>
    </div>
  );
}

function Sidebar({ nav, context, open, onClose }: { nav: NavItem[]; context: string; open: boolean; onClose: () => void }) {
  const router = useRouter();
  const [pending, setPending] = useState(false);

  async function logout() {
    setPending(true);
    try {
      await api.POST("/auth/logout");
    } finally {
      router.replace("/login");
    }
  }

  return (
    <>
      <div
        aria-hidden="true"
        onClick={onClose}
        className={`fixed inset-0 z-30 bg-zinc-950/50 backdrop-blur-sm transition-opacity lg:hidden ${open ? "opacity-100" : "pointer-events-none opacity-0"}`}
      />
      <aside
        className={`fixed inset-y-0 left-0 z-40 flex w-[17rem] shrink-0 flex-col bg-gradient-to-b from-brand-sidebar-from to-brand-sidebar-to text-white transition-transform duration-300 ease-out lg:sticky lg:top-0 lg:h-dvh lg:translate-x-0 ${open ? "translate-x-0" : "-translate-x-full"}`}
      >
        <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
          <div className="absolute -left-16 -top-16 size-56 rounded-full bg-brand-400/10 blur-3xl" />
          <div className="absolute -bottom-20 -right-10 size-64 rounded-full bg-gold-400/10 blur-3xl" />
        </div>

        <div className="relative flex items-center justify-between px-6 pb-3 pt-5">
          <Link href="/" className="flex items-center gap-3">
            <BrandMark />
            <span className="leading-tight">
              <span className="block text-lg font-bold tracking-tight">Sentra</span>
              <span className="block text-[11px] font-medium uppercase tracking-[0.14em] text-brand-200/80">Manajemen Sekolah</span>
            </span>
          </Link>
          <button type="button" onClick={onClose} aria-label="Tutup menu" className="rounded-lg p-1.5 text-white/70 hover:bg-white/10 lg:hidden">
            <IconClose />
          </button>
        </div>

        <p className="relative mx-6 mb-3 truncate rounded-lg bg-white/5 px-3 py-2 text-xs font-medium text-brand-100/90 ring-1 ring-white/10" title={context}>
          {context}
        </p>

        <nav aria-label="Navigasi utama" className="relative flex-1 overflow-y-auto pb-4 pl-4 [scrollbar-width:thin]">
          <ul className="space-y-0.5">
            {nav.map((item, index) => {
              const showGroup = item.group !== undefined && item.group !== nav[index - 1]?.group;
              const Icon = item.icon;

              return (
                <li key={item.href}>
                  {showGroup ? <p className="px-4 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-200/50">{item.group}</p> : null}
                  <Link
                    href={item.href}
                    aria-current={item.active ? "page" : undefined}
                    className={`group relative flex items-center gap-3 rounded-l-2xl py-2 pl-4 pr-4 text-sm font-medium transition-all duration-200 ${
                      item.active
                        ? "bg-[var(--background)] text-brand-800 before:absolute before:-top-5 before:right-0 before:size-5 before:rounded-br-2xl before:shadow-[8px_8px_0_8px_var(--background)] before:content-[''] after:absolute after:-bottom-5 after:right-0 after:size-5 after:rounded-tr-2xl after:shadow-[8px_-8px_0_8px_var(--background)] after:content-[''] dark:text-brand-300"
                        : "text-white/75 hover:translate-x-1 hover:bg-white/8 hover:text-white"
                    }`}
                  >
                    {Icon ? <Icon className={`shrink-0 transition-transform duration-200 ${item.active ? "scale-110" : "group-hover:scale-110"}`} /> : null}
                    <span className="truncate">{item.label}</span>
                  </Link>
                </li>
              );
            })}
          </ul>
        </nav>

        <div className="relative px-4 pb-5 pt-2">
          <button
            type="button"
            onClick={logout}
            disabled={pending}
            className="flex w-full items-center gap-3 rounded-xl px-4 py-2.5 text-sm font-medium text-white/75 transition-colors hover:bg-white/10 hover:text-white disabled:opacity-60"
          >
            <IconLogout />
            {pending ? "Keluar…" : "Keluar"}
          </button>
        </div>
      </aside>
    </>
  );
}

function TopBar({ context, userLabel, userName, demo, actions, onMenu }: { context: string; userLabel?: string; userName?: string; demo: boolean; actions?: ReactNode; onMenu?: () => void }) {
  const router = useRouter();
  const [menu, setMenu] = useState(false);
  const box = useRef<HTMLDivElement>(null);
  const name = userName ?? userLabel ?? "Akun";

  useEffect(() => {
    if (!menu) return;
    const away = (e: MouseEvent) => {
      if (box.current && !box.current.contains(e.target as Node)) setMenu(false);
    };
    const esc = (e: KeyboardEvent) => e.key === "Escape" && setMenu(false);
    document.addEventListener("mousedown", away);
    document.addEventListener("keydown", esc);
    return () => {
      document.removeEventListener("mousedown", away);
      document.removeEventListener("keydown", esc);
    };
  }, [menu]);

  async function logout() {
    try {
      await api.POST("/auth/logout");
    } finally {
      router.replace("/login");
    }
  }

  return (
    <header className="sticky top-0 z-20 flex items-center gap-3 bg-[var(--background)]/80 px-4 py-4 backdrop-blur-md sm:px-8">
      {onMenu ? (
        <button type="button" onClick={onMenu} aria-label="Buka menu" className="rounded-xl bg-white p-2.5 text-zinc-700 shadow-card lg:hidden dark:bg-zinc-900 dark:text-zinc-200">
          <IconMenu />
        </button>
      ) : (
        <Link href="/" className="flex items-center gap-2.5">
          <BrandMark size={34} />
          <span className="font-bold tracking-tight text-zinc-900 dark:text-zinc-50">Sentra</span>
        </Link>
      )}
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold text-zinc-900 dark:text-zinc-50">{context}</p>
        <p className="hidden text-xs text-zinc-500 sm:block">Sentra Sekolah</p>
      </div>

      {actions}
      <ThemeToggle />

      <div ref={box} className="relative">
        <button
          type="button"
          onClick={() => setMenu((v) => !v)}
          aria-haspopup="menu"
          aria-expanded={menu}
          className="flex items-center gap-2.5 rounded-full bg-white py-1.5 pl-1.5 pr-4 shadow-card transition-shadow hover:shadow-lift dark:bg-zinc-900"
        >
          <span className="flex size-8 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-brand-800 text-xs font-bold text-white">{initials(name)}</span>
          <span className="hidden max-w-40 truncate text-sm font-medium text-zinc-800 sm:inline dark:text-zinc-100">{name}</span>
        </button>
        {menu ? (
          <div role="menu" className="absolute right-0 mt-2 w-56 animate-pop overflow-hidden rounded-2xl border border-zinc-200/70 bg-white p-1.5 shadow-lift dark:border-zinc-800 dark:bg-zinc-900">
            {userLabel ? <p className="truncate px-3 py-2 text-xs text-zinc-500">{userLabel}</p> : null}
            {demo ? null : (
              <Link role="menuitem" href="/account/two-factor" className="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800">
                <IconShield width={18} height={18} /> Keamanan akun
              </Link>
            )}
            <button role="menuitem" type="button" onClick={logout} className="flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800">
              <IconLogout width={18} height={18} /> Keluar
            </button>
          </div>
        ) : null}
      </div>
    </header>
  );
}
