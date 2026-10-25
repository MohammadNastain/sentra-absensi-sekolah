"use client";

import { usePathname } from "next/navigation";
import { useSyncExternalStore } from "react";
import { IconBuilding, IconChart, IconShield, IconUserPlus } from "@/components/icons";
import type { NavItem } from "@/components/AppShell";
import { buildSchoolNav } from "@/lib/school-nav";

const LAST_SCHOOL_KEY = "sentra-last-school";
const listeners = new Set<() => void>();

/** Sekolah terakhir yang dibuka Super Admin; dipakai agar menu sekolah selalu ada di sidebar Platform. */
export function rememberSchool(code: string): void {
  try {
    if (localStorage.getItem(LAST_SCHOOL_KEY) === code) return;
    localStorage.setItem(LAST_SCHOOL_KEY, code);
    listeners.forEach((notify) => notify());
  } catch {
    // Penyimpanan diblokir: menu sekolah tetap muncul setelah membuka sekolah, hanya tidak diingat.
  }
}

export function hasRememberedSchool(): boolean {
  return lastSchool() !== null;
}

function lastSchool(): string | null {
  try {
    return localStorage.getItem(LAST_SCHOOL_KEY);
  } catch {
    return null;
  }
}

function subscribe(notify: () => void): () => void {
  listeners.add(notify);
  window.addEventListener("storage", notify);

  return () => {
    listeners.delete(notify);
    window.removeEventListener("storage", notify);
  };
}

/** Item grup Platform: dasbor, daftar sekolah (termasuk halaman detail), dan pembuatan sekolah baru. */
export function platformItems(pathname: string, group = "Platform"): NavItem[] {
  const creating = pathname === "/platform/new";
  const onDashboard = pathname === "/platform";
  const onAudit = pathname === "/platform/audit";
  const onPlatform = pathname.startsWith("/platform/");

  return [
    { href: "/platform", label: "Dasbor", icon: IconChart, group, active: onDashboard },
    { href: "/platform/schools", label: "Daftar sekolah", icon: IconBuilding, group, active: onPlatform && !creating && !onAudit },
    { href: "/platform/new", label: "Sekolah baru", icon: IconUserPlus, group, active: creating },
    { href: "/platform/audit", label: "Audit platform", icon: IconShield, group, active: onAudit },
  ];
}

/**
 * Sidebar Super Admin di halaman Platform: menu Platform, lalu seluruh menu sekolah terakhir yang dibuka
 * (Super Admin memegang semua izin Admin Sekolah, D-21).
 */
export function usePlatformNav(): NavItem[] {
  const pathname = usePathname();
  const school = useSyncExternalStore(subscribe, lastSchool, () => null);
  const items = platformItems(pathname);

  if (!school) return items;

  return [...items, ...buildSchoolNav(school, pathname, () => true, "Sekolah · ")];
}
