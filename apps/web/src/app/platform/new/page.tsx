"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import type { Schemas } from "@sentra/api-client";
import { AppShell } from "@/components/AppShell";
import { usePlatformNav } from "@/lib/platform-nav";
import { SchoolForm, type SchoolFormPayload } from "@/components/SchoolForm";
import { BackLink, PageHeader, SelectField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, type FormFailure } from "@/lib/errors";
import { useMe } from "@/lib/use-me";

export default function NewSchoolPage() {
  const router = useRouter();
  const me = useMe();
  const nav = usePlatformNav();
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);
  const [plans, setPlans] = useState<Schemas["Plan"][]>([]);
  const [status, setStatus] = useState<"active" | "trial">("active");

  useEffect(() => {
    // Hanya paket yang ditawarkan; bila gagal dimuat, API memakai paket bawaan (legacy).
    api
      .GET("/platform/plans")
      .then(({ data }) => setPlans((data?.data ?? []).filter((p) => p.is_active)))
      .catch(() => setPlans([]));
  }, []);

  async function create(payload: SchoolFormPayload, form: FormData) {
    const planCode = String(form.get("plan_code") ?? "");
    const trialEnds = String(form.get("trial_ends_on") ?? "");
    const subscription = {
      ...(planCode ? { plan_code: planCode } : {}),
      status,
      ...(status === "trial" && trialEnds ? { trial_ends_on: trialEnds } : {}),
    };
    setPending(true);
    setFailure(null);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/platform/schools", { body: { ...payload, ...subscription } });
    });
    setPending(false);

    if (!outcome.ok) {
      setFailure(outcome.failure);
      return;
    }
    const school = outcome.result.data?.data;
    router.replace(school ? `/platform/${school.code}` : "/platform/schools");
  }

  return (
    <AppShell context="Platform" nav={nav} userLabel={me?.email} userName={me?.name}>
      <PageHeader title="Sekolah baru" description="Daftarkan sekolah baru ke platform." actions={<BackLink href="/platform/schools">Semua sekolah</BackLink>} />
      <div className="max-w-xl rounded-2xl border border-zinc-200/70 bg-white shadow-card p-6 dark:border-zinc-800 dark:bg-zinc-900">
        <SchoolForm
          mode="create"
          failure={failure}
          pending={pending}
          submitLabel="Buat sekolah"
          extra={
            <div className="space-y-4 rounded-xl bg-zinc-50 p-4 dark:bg-zinc-950/40">
              {plans.length > 0 ? (
                <SelectField label="Paket" name="plan_code" defaultValue="standard" error={failure?.fields.plan_code}>
                  {plans.map((p) => (
                    <option key={p.code} value={p.code}>
                      {p.name} · Rp{p.price_per_student_idr.toLocaleString("id-ID")}/siswa/bulan
                    </option>
                  ))}
                </SelectField>
              ) : null}
              <SelectField label="Status awal" name="status_choice" value={status} onChange={(e) => setStatus(e.target.value === "trial" ? "trial" : "active")}>
                <option value="active">Aktif</option>
                <option value="trial">Uji coba</option>
              </SelectField>
              {status === "trial" ? (
                <TextField label="Uji coba berakhir (opsional)" name="trial_ends_on" type="date" error={failure?.fields.trial_ends_on} />
              ) : null}
            </div>
          }
          onSubmit={(payload, form) => {
            void create(payload, form);
          }}
        />
      </div>
    </AppShell>
  );
}
