"use client";

import type { Schemas } from "@sentra/api-client";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { use, useCallback, useEffect, useState } from "react";
import { AppShell } from "@/components/AppShell";
import { usePlatformNav } from "@/lib/platform-nav";
import { SchoolForm, type SchoolFormPayload } from "@/components/SchoolForm";
import { FormAlert, PrimaryButton, StatusBadge, TextAreaField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { redirectFor, useMe } from "@/lib/use-me";

type School = Schemas["School"];
type Invitation = Schemas["Invitation"];

const DELIVERY_LABELS: Record<Invitation["delivery_status"], string> = {
  queued: "Menunggu dikirim",
  handed_off: "Diserahkan ke layanan email",
  failed: "Gagal dikirim",
};

/** Detail sekolah (Super Admin): ubah profil, tangguhkan/aktifkan, undang Admin Sekolah pertama. */
export default function PlatformSchoolPage(props: PageProps<"/platform/[code]">) {
  const { code } = use(props.params);
  const router = useRouter();
  const me = useMe();
  const nav = usePlatformNav();
  const [school, setSchool] = useState<School | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, error } = await api.GET("/platform/schools/{school}", { params: { path: { school: code } } });
      const target = redirectFor(error);
      if (target) router.replace(target);
      else if (error !== undefined) setLoadError(toFormFailure(error).message);
      else if (data) setSchool(data.data);
    } catch (e) {
      setLoadError(toFormFailure(e).message);
    }
  }, [code, router]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat data awal halaman
    void load();
  }, [load]);

  return (
    <AppShell context="Platform" nav={nav} userLabel={me?.email} userName={me?.name}>
      <Link href="/platform" className="text-sm text-emerald-700 hover:underline dark:text-emerald-400">
        ← Semua sekolah
      </Link>

      {loadError ? <FormAlert>{loadError}</FormAlert> : null}
      {!school && !loadError ? (
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      ) : null}

      {school ? (
        <>
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-50">{school.name}</h1>
            <StatusBadge status={school.status} />
            <span className="font-mono text-sm text-zinc-500">{school.code}</span>
          </div>

          <ProfileSection school={school} onSaved={setSchool} />
          <StatusSection school={school} onChanged={setSchool} />
          <InviteAdminSection school={school} />
        </>
      ) : null}
    </AppShell>
  );
}

function Card({ title, description, children }: { title: string; description?: string; children: React.ReactNode }) {
  return (
    <section className="rounded-2xl border border-zinc-200/70 bg-white shadow-card p-6 dark:border-zinc-800 dark:bg-zinc-900">
      <h2 className="text-lg font-bold text-zinc-900 dark:text-zinc-50">{title}</h2>
      {description ? <p className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{description}</p> : null}
      <div className="mt-4 max-w-xl">{children}</div>
    </section>
  );
}

function ProfileSection({ school, onSaved }: { school: School; onSaved: (school: School) => void }) {
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);
  const [saved, setSaved] = useState(false);

  async function save({ name, npsn, education_levels, timezone, address }: SchoolFormPayload) {
    setPending(true);
    setFailure(null);
    setSaved(false);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.PATCH("/platform/schools/{school}", {
        params: { path: { school: school.code } },
        body: { name, npsn, education_levels, timezone, address },
      });
    });
    setPending(false);

    if (!outcome.ok) {
      setFailure(outcome.failure);
      return;
    }
    if (outcome.result.data) onSaved(outcome.result.data.data);
    setSaved(true);
  }

  return (
    <Card title="Profil sekolah" description="Kode sekolah tidak dapat diubah.">
      {saved ? <FormAlert tone="success">Profil disimpan.</FormAlert> : null}
      <div className={saved ? "mt-4" : undefined}>
        <SchoolForm
          key={`${school.name}|${school.npsn}|${school.timezone}|${school.address}|${school.education_levels.join()}`}
          mode="edit"
          initial={school}
          failure={failure}
          pending={pending}
          submitLabel="Simpan perubahan"
          onSubmit={(payload) => {
            void save(payload);
          }}
        />
      </div>
    </Card>
  );
}

function StatusSection({ school, onChanged }: { school: School; onChanged: (school: School) => void }) {
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);
  const suspended = school.status === "suspended";
  const canSuspend = school.status === "active" || school.status === "trial";

  async function change(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const reason = String(new FormData(event.currentTarget).get("reason") ?? "").trim();
    setPending(true);
    setFailure(null);

    const path = { params: { path: { school: school.code } } };
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return suspended
        ? api.POST("/platform/schools/{school}/activate", { ...path, body: { reason: reason || null } })
        : api.POST("/platform/schools/{school}/suspend", { ...path, body: { reason } });
    });
    setPending(false);

    if (!outcome.ok) {
      setFailure(outcome.failure);
      return;
    }
    if (outcome.result.data) onChanged(outcome.result.data.data);
  }

  if (!suspended && !canSuspend) return null;

  return (
    <Card
      title={suspended ? "Aktifkan kembali sekolah" : "Tangguhkan sekolah"}
      description={
        suspended
          ? "Seluruh anggota sekolah dapat mengakses kembali setelah sekolah diaktifkan."
          : "Seluruh anggota sekolah langsung kehilangan akses sampai sekolah diaktifkan kembali."
      }
    >
      <form onSubmit={change} noValidate className="space-y-4">
        {failure ? <FormAlert>{failure.message}</FormAlert> : null}
        <TextAreaField
          key={school.status}
          label={suspended ? "Alasan (opsional)" : "Alasan penangguhan"}
          name="reason"
          error={failure?.fields.reason}
          hint={suspended ? undefined : "Minimal 5 karakter. Tercatat di audit."}
        />
        <PrimaryButton type="submit" pending={pending}>
          {suspended ? "Aktifkan sekolah" : "Tangguhkan sekolah"}
        </PrimaryButton>
      </form>
    </Card>
  );
}

function InviteAdminSection({ school }: { school: School }) {
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);
  const [sent, setSent] = useState<Invitation | null>(null);

  async function invite(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const email = String(new FormData(form).get("email") ?? "").trim();
    setPending(true);
    setFailure(null);
    setSent(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/platform/schools/{school}/invitations", {
        params: { path: { school: school.code } },
        body: { email },
      });
    });
    setPending(false);

    if (!outcome.ok) {
      setFailure(outcome.failure);
      return;
    }
    setSent(outcome.result.data?.data ?? null);
    form.reset();
  }

  return (
    <Card
      title="Undang Admin Sekolah"
      description="Admin pertama menerima email berisi tautan untuk membuat akun. Peran selalu Admin Sekolah."
    >
      <form onSubmit={invite} noValidate className="space-y-4">
        {failure ? <FormAlert>{failure.message}</FormAlert> : null}
        {sent ? (
          <FormAlert tone="success">
            Undangan untuk {sent.email} dibuat. Status pengiriman: {DELIVERY_LABELS[sent.delivery_status]}.
          </FormAlert>
        ) : null}
        <TextField label="Email" name="email" type="email" autoComplete="off" error={failure?.fields.email} required />
        <PrimaryButton type="submit" pending={pending}>
          Kirim undangan
        </PrimaryButton>
      </form>
    </Card>
  );
}
