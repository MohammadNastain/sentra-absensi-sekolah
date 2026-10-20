"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { use, useEffect, useMemo, useState } from "react";
import { AppShell, type NavItem } from "@/components/AppShell";
import { IconBook, IconBuilding, IconCalendar, IconChart, IconCheck, IconClock, IconDevice, IconEdit, IconGrad, IconHome, IconMail, IconShield, IconSun, IconUserPlus, IconUsers } from "@/components/icons";
import { LogoutButton } from "@/components/LogoutButton";
import { Skeleton } from "@/components/ui";
import { api } from "@/lib/api";
import { toFormFailure } from "@/lib/errors";
import { redirectFor, useMe } from "@/lib/use-me";
import { SchoolProvider, type PermissionCode, type SchoolProfile } from "./school-context";

/** Memuat profil sekolah dan izin user; keanggotaan divalidasi API pada setiap request. */
export default function SchoolLayout(props: LayoutProps<"/schools/[code]">) {
  const { code } = use(props.params);
  const router = useRouter();
  const pathname = usePathname();
  const me = useMe();
  const [profile, setProfile] = useState<SchoolProfile | null>(null);
  const [blocked, setBlocked] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    api
      .GET("/schools/{school}", { params: { path: { school: code } } })
      .then(({ data, error }) => {
        if (cancelled) return;
        const target = redirectFor(error);
        if (target) router.replace(target);
        else if (error !== undefined) setBlocked(toFormFailure(error).message);
        else if (data) setProfile(data.data);
      })
      .catch((e) => {
        if (!cancelled) setBlocked(toFormFailure(e).message);
      });
    return () => {
      cancelled = true;
    };
  }, [code, router]);

  const value = useMemo(() => {
    if (!profile) return null;
    const granted = new Set<string>(profile.my_permissions);
    return { code, profile, meId: me?.id ?? null, meName: me?.name ?? null, setProfile, can: (permission: PermissionCode) => granted.has(permission) };
  }, [code, profile, me]);

  if (blocked) {
    return (
      <main className="mx-auto w-full max-w-2xl flex-1 space-y-6 px-4 py-10">
        <h1 className="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-50">Sekolah tidak dapat dibuka</h1>
        <p role="alert" className="text-zinc-700 dark:text-zinc-300">
          {blocked}
        </p>
        <div className="flex items-center gap-3">
          <Link href="/" className="font-medium text-brand-700 hover:underline dark:text-brand-400">
            Kembali
          </Link>
          <LogoutButton />
        </div>
      </main>
    );
  }

  if (!value) {
    return (
      <main role="status" aria-label="Memuat" className="mx-auto w-full max-w-[1280px] flex-1 space-y-6 px-4 py-10 sm:px-8">
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-44 w-full" />
        <div className="grid gap-4 sm:grid-cols-3">
          <Skeleton className="h-36" />
          <Skeleton className="h-36" />
          <Skeleton className="h-36" />
        </div>
      </main>
    );
  }

  const base = `/schools/${code}`;
  const on = (path: string) => pathname.startsWith(`${base}/${path}`);
  const nav: NavItem[] = [{ href: base, label: "Ringkasan", icon: IconHome, group: "Utama", active: pathname === base }];

  if (value.can("school.attendance.assist") || value.can("school.attendance.review")) {
    nav.push({ href: `${base}/attendance`, label: "Kehadiran", icon: IconCheck, group: "Kehadiran", active: on("attendance") });
  }
  if (value.can("school.attendance.correction_request")) {
    nav.push({ href: `${base}/corrections`, label: "Koreksi", icon: IconEdit, group: "Kehadiran", active: on("corrections") });
  }
  if (value.can("school.leave.manage")) {
    nav.push({ href: `${base}/leave`, label: "Izin", icon: IconMail, group: "Kehadiran", active: on("leave") });
  }
  if (value.can("school.reports.export")) {
    nav.push({ href: `${base}/reports`, label: "Laporan", icon: IconChart, group: "Kehadiran", active: on("reports") });
  }
  if (value.can("school.academic.view")) {
    nav.push({ href: `${base}/academic-years`, label: "Tahun ajaran", icon: IconBook, group: "Akademik", active: on("academic-years") });
    nav.push({ href: `${base}/classes`, label: "Kelas", icon: IconBuilding, group: "Akademik", active: on("classes") });
    nav.push({ href: `${base}/teachers`, label: "Guru", icon: IconGrad, group: "Akademik", active: on("teachers") });
  }
  if (value.can("school.students.view")) {
    nav.push({ href: `${base}/students`, label: "Siswa", icon: IconUsers, group: "Akademik", active: on("students") });
  }
  if (value.can("school.schedule.view")) {
    nav.push({ href: `${base}/schedules`, label: "Jadwal", icon: IconClock, group: "Jadwal", active: on("schedules") });
    nav.push({ href: `${base}/holidays`, label: "Libur", icon: IconSun, group: "Jadwal", active: on("holidays") });
    nav.push({ href: `${base}/calendar`, label: "Kalender", icon: IconCalendar, group: "Jadwal", active: on("calendar") });
  }
  if (value.can("school.members.view")) {
    nav.push({ href: `${base}/members`, label: "Anggota", icon: IconUsers, group: "Administrasi", active: on("members") });
  }
  if (value.can("school.members.manage")) {
    nav.push({ href: `${base}/invitations`, label: "Undangan", icon: IconUserPlus, group: "Administrasi", active: on("invitations") });
  }
  if (value.can("school.devices.view")) {
    nav.push({ href: `${base}/devices`, label: "Perangkat", icon: IconDevice, group: "Administrasi", active: on("devices") });
  }
  if (value.can("school.audit.view")) {
    nav.push({ href: `${base}/audit`, label: "Audit", icon: IconShield, group: "Administrasi", active: on("audit") });
  }

  return (
    <SchoolProvider value={value}>
      <AppShell context={value.profile.name} nav={nav} userLabel={me?.email} userName={me?.name}>
        {props.children}
      </AppShell>
    </SchoolProvider>
  );
}
