"use client";

import { useState } from "react";
import { SchoolForm, type SchoolFormPayload } from "@/components/SchoolForm";
import { FormAlert, StatusBadge } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, type FormFailure } from "@/lib/errors";
import { roleLabel } from "@/lib/school-options";
import { AttendanceToday } from "./attendance-today";
import { useSchool } from "./school-context";

/** Ringkasan sekolah dan (bagi pemilik izin) ubah profil. */
export default function SchoolHomePage() {
  const { code, profile, setProfile, can } = useSchool();
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

  return (
    <>
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{profile.name}</h1>
        <StatusBadge status={profile.status} />
      </div>

      {can("school.attendance.view") ? <AttendanceToday /> : null}

      <section className="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
        <h2 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">Profil</h2>
        <dl className="mt-4 grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
          <Item label="Kode" value={<span className="font-mono">{profile.code}</span>} />
          <Item label="NPSN" value={profile.npsn ?? "-"} />
          <Item label="Jenjang" value={profile.education_levels.join(", ")} />
          <Item label="Zona waktu" value={profile.timezone} />
          <Item label="Alamat" value={profile.address ?? "-"} />
          <Item label="Peran Anda" value={profile.my_roles.map(roleLabel).join(", ")} />
        </dl>
      </section>

      {can("school.profile.update") ? (
        <section className="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
          <h2 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">Ubah profil</h2>
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
    </>
  );
}

function Item({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <dt className="text-zinc-500">{label}</dt>
      <dd className="mt-0.5 text-zinc-900 dark:text-zinc-100">{value}</dd>
    </div>
  );
}
