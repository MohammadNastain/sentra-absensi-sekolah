"use client";

import { usePathname } from "next/navigation";
import { IconBuilding, IconUserPlus } from "@/components/icons";
import type { NavItem } from "@/components/AppShell";

/** Menu sidebar Super Admin: daftar sekolah (termasuk halaman detail) dan pembuatan sekolah baru. */
export function usePlatformNav(): NavItem[] {
  const pathname = usePathname();
  const creating = pathname === "/platform/new";

  return [
    { href: "/platform", label: "Daftar sekolah", icon: IconBuilding, group: "Platform", active: !creating && pathname.startsWith("/platform") },
    { href: "/platform/new", label: "Sekolah baru", icon: IconUserPlus, group: "Platform", active: creating },
  ];
}
