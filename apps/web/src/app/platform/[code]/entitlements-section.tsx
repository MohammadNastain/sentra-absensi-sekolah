"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Dialog } from "@/components/Dialog";
import { FormAlert, PrimaryButton, SecondaryButton, SelectField, TextAreaField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt } from "@/lib/errors";

type School = Schemas["School"];
type Entitlements = Schemas["SchoolEntitlements"];
type FeatureEntitlement = Schemas["FeatureEntitlement"];
type Plan = Schemas["Plan"];
type ChangeResult = Schemas["EntitlementChangeResult"];

/** Perubahan yang sedang diminta Super Admin; dijalankan dua tahap: pratinjau (dry_run) lalu penerapan. */
type Change =
  | { kind: "plan"; planCode: string; title: string }
  | { kind: "grant" | "revoke"; feature: string; title: string }
  | { kind: "remove"; feature: string; title: string };

interface Pending {
  change: Change;
  preview: ChangeResult;
}

const SOURCE_LABEL: Record<FeatureEntitlement["source"], string> = {
  plan: "Dari paket",
  override_grant: "Override: diaktifkan",
  override_revoke: "Override: dicabut",
  not_in_plan: "Di luar paket",
  blocked_by_dependency: "Menunggu prasyarat",
  unreleased: "Belum tersedia",
};

const SOURCE_TONE: Record<FeatureEntitlement["source"], string> = {
  plan: "bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300",
  override_grant: "bg-brand-100 text-brand-800 dark:bg-brand-950 dark:text-brand-200",
  override_revoke: "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300",
  not_in_plan: "bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300",
  blocked_by_dependency: "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300",
  unreleased: "bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400",
};

/**
 * "Akses Modul & Fitur" (ADR 0009 §9): paket sekolah dan checklist fitur dengan sumber akses. Semua aturan (prasyarat,
 * fitur belum rilis, dampak) ditegakkan API; komponen ini hanya mencegah klik yang pasti ditolak dan menampilkan pratinjau
 * dampak sebelum perubahan yang mematikan fitur lain diterapkan.
 */
export function EntitlementsSection({ school }: { school: School }) {
  const [data, setData] = useState<Entitlements | null>(null);
  const [plans, setPlans] = useState<Plan[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [pending, setPending] = useState<Pending | null>(null);
  const [planChoice, setPlanChoice] = useState<string | null>(null);

  const load = useCallback(async () => {
    const outcome = await attempt(async () => {
      const [entitlements, planList] = await Promise.all([
        api.GET("/platform/schools/{school}/entitlements", { params: { path: { school: school.code } } }),
        api.GET("/platform/plans"),
      ]);
      return { error: entitlements.error ?? planList.error, entitlements, planList };
    });
    if (!outcome.ok) {
      setError(outcome.failure.message);
      return;
    }
    if (outcome.result.entitlements.data) setData(outcome.result.entitlements.data.data);
    if (outcome.result.planList.data) setPlans(outcome.result.planList.data.data);
  }, [school.code]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat data awal bagian ini
    void load();
  }, [load]);

  const labels = useMemo(() => new Map((data?.features ?? []).map((f) => [f.code, f.label])), [data]);
  const enabled = useMemo(() => new Set((data?.features ?? []).filter((f) => f.enabled).map((f) => f.code)), [data]);
  const label = (code: string) => labels.get(code) ?? code;

  /** Memanggil endpoint yang sesuai; `dry_run` hanya meminta pratinjau dampak. */
  async function send(change: Change, options: { dryRun: boolean; confirm: boolean; reason: string | null }) {
    const common = { reason: options.reason, dry_run: options.dryRun, confirm_impact: options.confirm };
    const path = { school: school.code };

    return attempt(async () => {
      await api.ensureCsrfCookie();
      if (change.kind === "plan") {
        return api.PUT("/platform/schools/{school}/subscription", { params: { path }, body: { plan_code: change.planCode, ...common } });
      }
      if (change.kind === "remove") {
        return api.DELETE("/platform/schools/{school}/entitlements/{feature}", { params: { path: { ...path, feature: change.feature } }, body: common });
      }
      return api.PUT("/platform/schools/{school}/entitlements/{feature}", {
        params: { path: { ...path, feature: change.feature } },
        body: { effect: change.kind, ...common },
      });
    });
  }

  /** Tahap 1: pratinjau. Ganti paket dan perubahan yang mematikan fitur lain meminta konfirmasi; sisanya langsung diterapkan. */
  async function request(change: Change) {
    setBusy(true);
    setError(null);
    setNotice(null);
    const preview = await send(change, { dryRun: true, confirm: false, reason: null });

    if (!preview.ok) {
      setBusy(false);
      setError(preview.failure.message);
      return;
    }
    const result = preview.result.data?.data;
    if (!result) {
      setBusy(false);
      return;
    }
    if (result.unchanged) {
      setBusy(false);
      setNotice("Tidak ada perubahan: pengaturan sudah seperti itu.");
      return;
    }
    if (change.kind === "plan" || result.requires_confirmation) {
      setBusy(false);
      setPending({ change, preview: result });
      return;
    }
    await apply(change, null, false);
  }

  /** Tahap 2: penerapan. */
  async function apply(change: Change, reason: string | null, confirm: boolean) {
    setBusy(true);
    const outcome = await send(change, { dryRun: false, confirm, reason });
    setBusy(false);

    if (!outcome.ok) {
      setPending(null);
      setError(outcome.failure.message);
      return;
    }
    const result = outcome.result.data?.data;
    if (result?.entitlements) setData(result.entitlements);
    setPending(null);
    setPlanChoice(null);
    setNotice(`${change.title} berhasil diterapkan.`);
  }

  function toggle(feature: FeatureEntitlement) {
    if (feature.enabled) {
      // Mematikan: override yang menambah fitur di luar paket cukup dihapus; selain itu dicabut.
      const change: Change =
        feature.override === "grant" && !feature.in_plan
          ? { kind: "remove", feature: feature.code, title: `Pencabutan ${feature.label}` }
          : { kind: "revoke", feature: feature.code, title: `Pencabutan ${feature.label}` };
      void request(change);
      return;
    }
    const change: Change =
      feature.override === "revoke" && feature.in_plan
        ? { kind: "remove", feature: feature.code, title: `Pemulihan ${feature.label}` }
        : { kind: "grant", feature: feature.code, title: `Pengaktifan ${feature.label}` };
    void request(change);
  }

  const subscription = data?.subscription ?? null;
  const offered = plans.filter((p) => p.is_active || p.code === subscription?.plan_code);
  const chosen = planChoice ?? subscription?.plan_code ?? "";
  const chosenName = plans.find((p) => p.code === chosen)?.name ?? chosen;
  const roots = (data?.features ?? []).filter((f) => f.parent === null);

  return (
    <section className="rounded-2xl border border-zinc-200/70 bg-white p-6 shadow-card dark:border-zinc-800 dark:bg-zinc-900">
      <h2 className="text-lg font-bold text-zinc-900 dark:text-zinc-50">Akses modul &amp; fitur</h2>
      <p className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
        Paket menentukan fitur bawaan; override mengubahnya untuk sekolah ini. Menonaktifkan fitur hanya mencabut akses, data tidak dihapus.
      </p>

      <div className="mt-4 space-y-4">
        {error ? <FormAlert>{error}</FormAlert> : null}
        {notice ? <FormAlert tone="success">{notice}</FormAlert> : null}
        {!data && !error ? (
          <p role="status" className="text-sm text-zinc-600 dark:text-zinc-400">
            Memuat…
          </p>
        ) : null}

        {data ? (
          <>
            <div className="flex flex-wrap items-end gap-3 rounded-xl bg-zinc-50 p-4 dark:bg-zinc-950/40">
              <div className="min-w-48 flex-1">
                <SelectField label="Paket" name="plan_code" value={chosen} onChange={(e) => setPlanChoice(e.target.value)} disabled={busy}>
                  {subscription ? null : <option value="">Belum berlangganan</option>}
                  {offered.map((p) => (
                    <option key={p.code} value={p.code}>
                      {p.name}
                      {p.is_active ? ` · Rp${p.price_per_student_idr.toLocaleString("id-ID")}/siswa/bulan` : " · tidak ditawarkan"}
                    </option>
                  ))}
                </SelectField>
              </div>
              <PrimaryButton
                type="button"
                disabled={busy || chosen === "" || chosen === subscription?.plan_code}
                onClick={() => void request({ kind: "plan", planCode: chosen, title: `Penggantian paket ke ${chosenName}` })}
              >
                Ganti paket
              </PrimaryButton>
              {subscription ? (
                <p className="w-full text-xs text-zinc-500">
                  Berlangganan sejak {subscription.starts_on}
                  {subscription.status === "trial" ? " (uji coba)" : ""}. Harga bersifat hipotesis dan belum menjadi tagihan.
                </p>
              ) : null}
            </div>

            <ul className="space-y-3">
              {roots.map((root) => (
                <li key={root.code} className="rounded-xl border border-zinc-200 p-3 dark:border-zinc-800">
                  <FeatureRow feature={root} enabled={enabled} label={label} busy={busy} onToggle={toggle} />
                  <ul className="mt-1 space-y-1 border-l border-zinc-200 pl-4 dark:border-zinc-800 ml-2">
                    {data.features
                      .filter((f) => f.parent === root.code)
                      .map((child) => (
                        <li key={child.code}>
                          <FeatureRow feature={child} enabled={enabled} label={label} busy={busy} onToggle={toggle} />
                        </li>
                      ))}
                  </ul>
                </li>
              ))}
            </ul>
          </>
        ) : null}
      </div>

      <ImpactDialog pending={pending} busy={busy} label={label} onCancel={() => setPending(null)} onConfirm={(reason) => pending && void apply(pending.change, reason, true)} />
    </section>
  );
}

function FeatureRow({
  feature,
  enabled,
  label,
  busy,
  onToggle,
}: {
  feature: FeatureEntitlement;
  enabled: ReadonlySet<string>;
  label: (code: string) => string;
  busy: boolean;
  onToggle: (feature: FeatureEntitlement) => void;
}) {
  // Menyalakan fitur yang prasyaratnya mati pasti ditolak API; cegah kliknya dan jelaskan alasannya.
  const prerequisites = [feature.parent, ...feature.requires].filter((c): c is string => c !== null);
  const unmet = feature.enabled ? [] : prerequisites.filter((c) => !enabled.has(c));
  const note = !feature.released
    ? "Belum tersedia di aplikasi, tidak dapat diaktifkan."
    : feature.source === "blocked_by_dependency"
      ? `Menunggu: ${feature.missing.map(label).join(", ")}.`
      : unmet.length > 0
        ? `Aktifkan dulu: ${unmet.map(label).join(", ")}.`
        : null;
  const disabled = busy || !feature.released || feature.source === "blocked_by_dependency" || unmet.length > 0;
  const id = `feature-${feature.code}`;

  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 py-1.5">
      <input
        id={id}
        type="checkbox"
        checked={feature.enabled}
        disabled={disabled}
        aria-describedby={note ? `${id}-note` : undefined}
        onChange={() => onToggle(feature)}
        className="size-4 accent-brand-600"
      />
      <label htmlFor={id} className="min-w-0 flex-1 text-sm font-medium text-zinc-900 dark:text-zinc-100">
        {feature.label} <span className="font-mono text-xs font-normal text-zinc-500">{feature.code}</span>
      </label>
      <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${SOURCE_TONE[feature.source]}`}>{SOURCE_LABEL[feature.source]}</span>
      {note ? (
        <p id={`${id}-note`} className="w-full pl-7 text-xs text-zinc-500">
          {note}
        </p>
      ) : null}
    </div>
  );
}

/** Pratinjau dampak: fitur yang ikut mati/bertambah dan override yang jadi redundan, plus alasan opsional untuk audit. */
function ImpactDialog({
  pending,
  busy,
  label,
  onCancel,
  onConfirm,
}: {
  pending: Pending | null;
  busy: boolean;
  label: (code: string) => string;
  onCancel: () => void;
  onConfirm: (reason: string | null) => void;
}) {
  const impact = pending?.preview.impact;
  // Fitur yang sedang dicabut itu sendiri tidak dihitung sebagai "ikut mati".
  const target = pending && pending.change.kind !== "plan" ? pending.change.feature : null;
  const lost = (impact?.lost ?? []).filter((c) => c !== target);

  return (
    <Dialog open={pending !== null} title={pending ? `${pending.change.title}: terapkan?` : ""} onClose={onCancel}>
      {pending && impact ? (
        <form
          noValidate
          className="space-y-4"
          onSubmit={(event) => {
            event.preventDefault();
            const reason = String(new FormData(event.currentTarget).get("reason") ?? "").trim();
            onConfirm(reason === "" ? null : reason);
          }}
        >
          {lost.length > 0 ? (
            <div>
              <p className="text-sm font-semibold text-amber-800 dark:text-amber-300">Fitur berikut ikut tidak bisa dipakai:</p>
              <ul className="mt-1 list-disc pl-5 text-sm text-zinc-700 dark:text-zinc-300">
                {lost.map((c) => (
                  <li key={c}>{label(c)}</li>
                ))}
              </ul>
            </div>
          ) : (
            <p className="text-sm text-zinc-700 dark:text-zinc-300">Tidak ada fitur yang hilang.</p>
          )}
          {impact.gained.length > 0 ? (
            <div>
              <p className="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Fitur yang bertambah:</p>
              <ul className="mt-1 list-disc pl-5 text-sm text-zinc-700 dark:text-zinc-300">
                {impact.gained.map((c) => (
                  <li key={c}>{label(c)}</li>
                ))}
              </ul>
            </div>
          ) : null}
          {pending.change.kind === "plan" && impact.redundant_overrides.length > 0 ? (
            <p className="text-sm text-zinc-600 dark:text-zinc-400">
              Override berikut tetap tersimpan, tetapi kini sama dengan paket baru: {impact.redundant_overrides.map(label).join(", ")}.
            </p>
          ) : null}
          <p className="text-xs text-zinc-500">Data sekolah tidak dihapus; hanya akses yang berubah.</p>
          <TextAreaField label="Alasan (opsional)" name="reason" maxLength={500} hint="Tercatat di audit." />
          <div className="flex justify-end gap-3">
            <SecondaryButton type="button" onClick={onCancel} disabled={busy}>
              Batal
            </SecondaryButton>
            <PrimaryButton type="submit" pending={busy}>
              Terapkan
            </PrimaryButton>
          </div>
        </form>
      ) : null}
    </Dialog>
  );
}

