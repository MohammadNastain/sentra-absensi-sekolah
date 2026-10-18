"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { use, useEffect, useMemo, useState } from "react";
import { AppShell, type NavItem } from "@/components/AppShell";
import { LogoutButton } from "@/components/LogoutButton";
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
    return { code, profile, meId: me?.id ?? null, setProfile, can: (permission: PermissionCode) => granted.has(permission) };
  }, [code, profile, me]);

  if (blocked) {
    return (
      <main className="mx-auto w-full max-w-2xl flex-1 space-y-6 px-4 py-10">
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">Sekolah tidak dapat dibuka</h1>
        <p role="alert" className="text-zinc-700 dark:text-zinc-300">
          {blocked}
        </p>
        <div className="flex items-center gap-3">
          <Link href="/" className="font-medium text-emerald-700 hover:underline dark:text-emerald-400">
            Kembali
          </Link>
          <LogoutButton />
        </div>
      </main>
    );
  }

  if (!value) {
    return (
      <main className="flex flex-1 items-center justify-center text-zinc-600 dark:text-zinc-400">
        <p role="status">Memuat…</p>
      </main>
    );
  }

  const base = `/schools/${code}`;
  const nav: NavItem[] = [{ href: base, label: "Ringkasan", active: pathname === base }];
  if (value.can("school.members.view")) {
    nav.push({ href: `${base}/members`, label: "Anggota", active: pathname.startsWith(`${base}/members`) });
  }
  if (value.can("school.members.manage")) {
    nav.push({ href: `${base}/invitations`, label: "Undangan", active: pathname.startsWith(`${base}/invitations`) });
  }
  if (value.can("school.academic.view")) {
    nav.push({ href: `${base}/academic-years`, label: "Tahun ajaran", active: pathname.startsWith(`${base}/academic-years`) });
    nav.push({ href: `${base}/classes`, label: "Kelas", active: pathname.startsWith(`${base}/classes`) });
    nav.push({ href: `${base}/teachers`, label: "Guru", active: pathname.startsWith(`${base}/teachers`) });
  }
  if (value.can("school.students.view")) {
    nav.push({ href: `${base}/students`, label: "Siswa", active: pathname.startsWith(`${base}/students`) });
  }
  if (value.can("school.schedule.view")) {
    nav.push({ href: `${base}/schedules`, label: "Jadwal", active: pathname.startsWith(`${base}/schedules`) });
    nav.push({ href: `${base}/holidays`, label: "Libur", active: pathname.startsWith(`${base}/holidays`) });
    nav.push({ href: `${base}/calendar`, label: "Kalender", active: pathname.startsWith(`${base}/calendar`) });
  }
  if (value.can("school.devices.view")) {
    nav.push({ href: `${base}/devices`, label: "Perangkat", active: pathname.startsWith(`${base}/devices`) });
  }
  if (value.can("school.audit.view")) {
    nav.push({ href: `${base}/audit`, label: "Audit", active: pathname.startsWith(`${base}/audit`) });
  }

  return (
    <SchoolProvider value={value}>
      <AppShell context={value.profile.name} nav={nav} userLabel={me?.email}>
        {props.children}
      </AppShell>
    </SchoolProvider>
  );
}
