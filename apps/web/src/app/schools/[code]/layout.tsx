"use client";

import { usePathname, useRouter } from "next/navigation";
import { use, useEffect, useMemo, useState } from "react";
import { AppShell, type NavItem } from "@/components/AppShell";
import { LogoutButton } from "@/components/LogoutButton";
import { PlatformBanner } from "@/components/PlatformBanner";
import { IconArrowLeft } from "@/components/icons";
import { EmptyState, LinkButton, Skeleton } from "@/components/ui";
import { api } from "@/lib/api";
import { toFormFailure } from "@/lib/errors";
import { requiredFeatures } from "@/lib/features";
import { platformItems, rememberSchool } from "@/lib/platform-nav";
import { buildSchoolNav } from "@/lib/school-nav";
import { redirectFor, useMe } from "@/lib/use-me";
import { SchoolProvider, type MyEntitlements, type PermissionCode, type SchoolProfile } from "./school-context";

/** Memuat profil sekolah dan izin user; keanggotaan divalidasi API pada setiap request. */
export default function SchoolLayout(props: LayoutProps<"/schools/[code]">) {
  const { code } = use(props.params);
  const router = useRouter();
  const pathname = usePathname();
  const me = useMe();
  const [profile, setProfile] = useState<SchoolProfile | null>(null);
  const [blocked, setBlocked] = useState<string | null>(null);
  // null = belum dimuat atau gagal dimuat: menu tidak disaring (fail-open untuk UX; API tetap menegakkan entitlement).
  const [entitlements, setEntitlements] = useState<MyEntitlements | null>(null);
  // Halaman baru dirender setelah daftar fitur selesai dimuat (berhasil atau gagal) agar tidak berkedip memanggil API yang ditolak.
  const [entitlementsLoaded, setEntitlementsLoaded] = useState(false);

  useEffect(() => {
    let cancelled = false;
    api
      .GET("/schools/{school}/entitlements", { params: { path: { school: code } } })
      .then(({ data }) => {
        if (!cancelled && data) setEntitlements(data.data);
      })
      .catch(() => {
        // Daftar fitur hanya menyaring tampilan; kegagalan memuatnya tidak menghalangi sekolah dibuka.
      })
      .finally(() => {
        if (!cancelled) setEntitlementsLoaded(true);
      });
    return () => {
      cancelled = true;
    };
  }, [code]);

  useEffect(() => {
    let cancelled = false;
    api
      .GET("/schools/{school}", { params: { path: { school: code } } })
      .then(({ data, error }) => {
        if (cancelled) return;
        const target = redirectFor(error);
        if (target) router.replace(target);
        else if (error !== undefined) setBlocked(toFormFailure(error).message);
        else if (data) {
          setProfile(data.data);
          if (data.data.acting_as_platform_admin) rememberSchool(code);
        }
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
    const features = entitlements === null ? null : new Set<string>(entitlements.features);
    return {
      code,
      profile,
      meId: me?.id ?? null,
      meName: me?.name ?? null,
      setProfile,
      can: (permission: PermissionCode) => granted.has(permission),
      planName: entitlements?.plan?.name ?? null,
      hasFeature: (feature: string) => features === null || features.has(feature),
    };
  }, [code, profile, me, entitlements]);

  if (blocked) {
    return (
      <main className="mx-auto w-full max-w-2xl flex-1 space-y-6 px-4 py-10">
        <h1 className="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-50">Sekolah tidak dapat dibuka</h1>
        <p role="alert" className="text-zinc-700 dark:text-zinc-300">
          {blocked}
        </p>
        <div className="flex items-center gap-3">
          <LinkButton href="/" icon={<IconArrowLeft width={16} height={16} />}>
            Kembali
          </LinkButton>
          <LogoutButton />
        </div>
      </main>
    );
  }

  if (!value || !entitlementsLoaded) {
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

  const schoolNav = buildSchoolNav(code, pathname, value.can, "", value.hasFeature);
  const needed = requiredFeatures(pathname, code);
  const unavailable = needed !== null && !needed.some(value.hasFeature);
  // Super Admin melihat menu Platform dan menu sekolah dalam satu sidebar.
  const nav: NavItem[] = value.profile.acting_as_platform_admin ? [...platformItems(pathname), ...schoolNav.map((item) => ({ ...item, group: `Sekolah · ${item.group}` }))] : schoolNav;

  return (
    <SchoolProvider value={value}>
      <AppShell context={value.profile.name} nav={nav} userLabel={me?.email} userName={me?.name}>
        {value.profile.acting_as_platform_admin ? <PlatformBanner code={code} /> : null}
        {unavailable ? (
          <EmptyState
            title="Fitur ini tidak termasuk paket sekolah Anda"
            description={
              <>
                {value.planName ? `Paket saat ini: ${value.planName}. ` : null}
                Hubungi admin Sentra untuk mengaktifkan modul ini. Data yang sudah ada tetap tersimpan.
              </>
            }
            action={<LinkButton href={`/schools/${code}`}>Kembali ke ringkasan</LinkButton>}
          />
        ) : (
          props.children
        )}
      </AppShell>
    </SchoolProvider>
  );
}
