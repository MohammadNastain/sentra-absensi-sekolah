"use client";

import Link from "next/link";
import { useState } from "react";
import { IconArrowRight } from "@/components/icons";
import { SchoolForm, type SchoolFormPayload } from "@/components/SchoolForm";
import { FormAlert, StatusBadge } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, type FormFailure } from "@/lib/errors";
import { roleLabel } from "@/lib/school-options";
import { AttendanceToday } from "./attendance-today";
import { AttendanceTrend } from "./attendance-trend";
import { useSchool } from "./school-context";

/** Ringkasan sekolah dan (bagi pemilik izin) ubah profil. */
export default function SchoolHomePage() {
  const { code, profile, meName, setProfile, can, hasFeature } = useSchool();
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);
  const [saved, setSaved] = useState(false);

  async function save({ name, npsn, education_levels, timezone, address }: SchoolFormPayload) {
    setPending(true);
    setFailure(null);
    setSaved(false);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.PATCH("/schools/{school}", {
        params: { path: { school: code } },
        body: { name, npsn, education_levels, timezone, address },
      });
    });
    setPending(false);

    if (!outcome.ok) {
      setFailure(outcome.failure);
      return;
    }
    if (outcome.result.data) setProfile(outcome.result.data.data);
    setSaved(true);
  }

  const firstName = (meName ?? "").split(" ")[0];
  // Widget kehadiran hanya bila modul absensi dimiliki; tren memakai data rekap (attendance.reports).
  const viewAttendance = can("school.attendance.view") && hasFeature("attendance");
  const viewTrend = viewAttendance && hasFeature("attendance.reports");

  return (
    <div className="space-y-6">
      <section className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-700 via-brand-800 to-brand-sidebar-to p-7 text-white shadow-lift sm:p-9">
        <div aria-hidden="true" className="pointer-events-none absolute inset-0">
          <div className="absolute -right-10 -top-16 size-64 animate-float rounded-full bg-brand-300/15 blur-2xl" />
          <div className="absolute -bottom-24 right-40 size-56 animate-float rounded-full bg-gold-400/15 blur-2xl [animation-delay:-3s]" />
        </div>
        <HeroArt />
        <div className="relative max-w-xl">
          <div className="flex flex-wrap items-center gap-2.5">
            <StatusBadge status={profile.status} />
            <span className="rounded-full bg-white/10 px-3 py-1 text-xs font-medium ring-1 ring-white/15">{profile.my_roles.map(roleLabel).join(", ")}</span>
          </div>
          <h1 className="mt-4 text-3xl font-bold tracking-tight sm:text-4xl">{firstName ? `Halo, ${firstName}!` : "Selamat datang!"}</h1>
          <p className="mt-2 text-base leading-relaxed text-brand-50/80">
            {hasFeature("attendance") ? (
              <>
                Ringkasan kehadiran <span className="font-semibold text-white">{profile.name}</span> hari ini, diperbarui otomatis.
              </>
            ) : (
              <>
                Selamat datang di <span className="font-semibold text-white">{profile.name}</span>.
              </>
            )}
          </p>
          <div className="mt-6 flex flex-wrap gap-3">
            {hasFeature("attendance") && (can("school.attendance.review") || can("school.attendance.assist")) ? (
              <HeroLink href={`/schools/${code}/attendance`} primary>
                Buka kehadiran
              </HeroLink>
            ) : null}
            {hasFeature("attendance.reports") && can("school.reports.export") ? <HeroLink href={`/schools/${code}/reports`}>Lihat laporan</HeroLink> : null}
          </div>
        </div>
      </section>

      {viewAttendance ? <AttendanceToday /> : null}

      <div className="grid gap-6 xl:grid-cols-3">
        {viewTrend ? (
          <div className="xl:col-span-2">
            <AttendanceTrend />
          </div>
        ) : null}
        <section className={`rounded-2xl border border-zinc-200/70 bg-white p-6 shadow-card dark:border-zinc-800 dark:bg-zinc-900 ${viewTrend ? "" : "xl:col-span-3"}`}>
          <h2 className="text-lg font-bold text-zinc-900 dark:text-zinc-50">Profil sekolah</h2>
          <dl className="mt-4 grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2 xl:grid-cols-1">
            <Item label="Kode" value={<span className="font-mono">{profile.code}</span>} />
            <Item label="NPSN" value={profile.npsn ?? "-"} />
            <Item label="Jenjang" value={profile.education_levels.join(", ")} />
            <Item label="Zona waktu" value={profile.timezone} />
            <Item label="Alamat" value={profile.address ?? "-"} />
          </dl>
        </section>
      </div>

      {can("school.profile.update") ? (
        <section className="rounded-2xl border border-zinc-200/70 bg-white p-6 shadow-card dark:border-zinc-800 dark:bg-zinc-900">
          <h2 className="text-lg font-bold text-zinc-900 dark:text-zinc-50">Ubah profil</h2>
          <div className="mt-4 max-w-xl space-y-4">
            {saved ? <FormAlert tone="success">Profil disimpan.</FormAlert> : null}
            <SchoolForm
              key={`${profile.name}|${profile.npsn}|${profile.timezone}|${profile.address}|${profile.education_levels.join()}`}
              mode="edit"
              initial={profile}
              failure={failure}
              pending={pending}
              submitLabel="Simpan perubahan"
              onSubmit={(payload) => {
                void save(payload);
              }}
            />
          </div>
        </section>
      ) : null}
    </div>
  );
}

function HeroLink({ href, primary, children }: { href: string; primary?: boolean; children: React.ReactNode }) {
  return (
    <Link
      href={href}
      className={`group inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition duration-200 hover:-translate-y-0.5 ${
        primary ? "bg-white text-brand-800 shadow-md hover:shadow-lg" : "bg-white/10 text-white ring-1 ring-white/25 hover:bg-white/15"
      }`}
    >
      {children}
      <IconArrowRight width={16} height={16} className="transition-transform duration-200 group-hover:translate-x-1" />
    </Link>
  );
}

/** Ilustrasi abstrak (jam, centang, gedung sekolah) di sisi kanan kartu sambutan; hanya tampil di layar lebar. */
function HeroArt() {
  return (
    <svg aria-hidden="true" viewBox="0 0 320 240" className="pointer-events-none absolute -bottom-2 right-6 hidden h-60 w-auto md:block">
      <g className="animate-float">
        <rect x="70" y="92" width="180" height="110" rx="14" fill="white" fillOpacity="0.1" stroke="white" strokeOpacity="0.35" />
        <path d="M70 110 160 52l90 58" fill="white" fillOpacity="0.14" stroke="white" strokeOpacity="0.4" strokeLinejoin="round" />
        <rect x="140" y="146" width="40" height="56" rx="6" fill="white" fillOpacity="0.28" />
        <g fill="white" fillOpacity="0.28">
          <rect x="90" y="120" width="30" height="24" rx="4" />
          <rect x="200" y="120" width="30" height="24" rx="4" />
          <rect x="90" y="156" width="30" height="24" rx="4" />
          <rect x="200" y="156" width="30" height="24" rx="4" />
        </g>
        <circle cx="160" cy="92" r="9" fill="#e2b65c" />
      </g>
      <g className="animate-float [animation-delay:-2s]">
        <circle cx="268" cy="62" r="26" fill="white" fillOpacity="0.14" stroke="white" strokeOpacity="0.45" />
        <path d="m256 62 8 8 14-16" fill="none" stroke="#e2b65c" strokeWidth="4" strokeLinecap="round" strokeLinejoin="round" />
      </g>
      <g className="animate-float [animation-delay:-4s]">
        <circle cx="52" cy="70" r="20" fill="white" fillOpacity="0.12" stroke="white" strokeOpacity="0.4" />
        <path d="M52 58v13l8 5" fill="none" stroke="white" strokeOpacity="0.85" strokeWidth="3" strokeLinecap="round" />
      </g>
    </svg>
  );
}

function Item({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-semibold uppercase tracking-wide text-zinc-500">{label}</dt>
      <dd className="mt-1 font-medium text-zinc-900 dark:text-zinc-100">{value}</dd>
    </div>
  );
}
