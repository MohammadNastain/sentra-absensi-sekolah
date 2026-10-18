"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useMemo, useState } from "react";
import { FormAlert, PrimaryButton, SecondaryButton, TextAreaField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { toFormFailure } from "@/lib/errors";
import { useSchool } from "../school-context";

type PendingAbsences = Schemas["PendingAbsencesEnvelope"]["data"];

const CARD = "space-y-4 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900";

const HEALTH_REASONS: Record<string, string> = {
  NO_SCANS: "Tidak ada satu pun scan yang tercatat padahal ada siswa wajib hadir.",
  MANUAL: "Ditandai bergangguan oleh petugas.",
};

/** Tanggal hari ini menurut zona waktu sekolah, "YYYY-MM-DD". */
export function schoolToday(timeZone: string, offsetDays = 0): string {
  const date = new Date(Date.now() + offsetDays * 86_400_000);
  return new Intl.DateTimeFormat("en-CA", { timeZone, year: "numeric", month: "2-digit", day: "2-digit" }).format(date);
}

/** Pengaturan aturan alpa sementara: hanya yang berhak mengubah profil sekolah. */
export function AbsenceSettingsPanel() {
  const { code } = useSchool();
  const [enabled, setEnabled] = useState(false);
  const [cutoff, setCutoff] = useState("");
  const [loaded, setLoaded] = useState(false);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldError, setFieldError] = useState<string | undefined>();
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    let active = true;
    void (async () => {
      try {
        const { data, error: problem } = await api.GET("/schools/{school}/attendance/absence-settings", { params: { path: { school: code } } });
        if (!active) return;
        if (problem !== undefined) setError(toFormFailure(problem).message);
        else if (data) {
          setEnabled(data.data.enabled);
          setCutoff(data.data.cutoff_time ?? "");
          setLoaded(true);
        }
      } catch (e) {
        if (active) setError(toFormFailure(e).message);
      }
    })();
    return () => {
      active = false;
    };
  }, [code]);

  async function save(event: React.FormEvent) {
    event.preventDefault();
    setPending(true);
    setError(null);
    setFieldError(undefined);
    setSaved(false);
    try {
      const { error: problem } = await api.PUT("/schools/{school}/attendance/absence-settings", {
        params: { path: { school: code } },
        body: { enabled, cutoff_time: enabled && cutoff !== "" ? cutoff : null },
      });
      if (problem !== undefined) {
        const failure = toFormFailure(problem);
        setError(failure.message);
        setFieldError(failure.fields.cutoff_time);
      } else setSaved(true);
    } catch (e) {
      setError(toFormFailure(e).message);
    } finally {
      setPending(false);
    }
  }

  return (
    <section aria-labelledby="absence-settings-title" className={CARD}>
      <div>
        <h2 id="absence-settings-title" className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
          Aturan alpa
        </h2>
        <p className="text-sm text-zinc-600 dark:text-zinc-400">
          Setelah jam batas, siswa tanpa catatan ditandai alpa sementara. Status itu belum dihitung sebagai alpa resmi sampai diverifikasi petugas.
        </p>
      </div>
      {saved ? <FormAlert tone="success">Pengaturan disimpan.</FormAlert> : null}
      {error ? <FormAlert>{error}</FormAlert> : null}
      {loaded ? (
        <form noValidate onSubmit={save} className="max-w-sm space-y-4">
          <label className="flex items-center gap-2 text-sm text-zinc-800 dark:text-zinc-200">
            <input type="checkbox" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
            <span>Aktifkan alpa sementara otomatis</span>
          </label>
          {enabled ? <TextField label="Jam batas (waktu lokal sekolah)" name="cutoff_time" type="time" value={cutoff} onChange={(e) => setCutoff(e.target.value)} error={fieldError} /> : null}
          <PrimaryButton type="submit" pending={pending}>
            Simpan
          </PrimaryButton>
        </form>
      ) : error ? null : (
        <p role="status" className="text-sm text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      )}
    </section>
  );
}

/** Daftar alpa sementara satu tanggal: verifikasi massal per kelas, serta tandai/lepas penahanan hari. */
export function AbsenceVerificationPanel({ canHold }: { canHold: boolean }) {
  const { code, profile } = useSchool();
  const [date, setDate] = useState(() => schoolToday(profile.timezone));
  const [data, setData] = useState<PendingAbsences | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [reason, setReason] = useState("");
  const [action, setAction] = useState<"verify" | "hold" | "release" | null>(null);
  const [pending, setPending] = useState(false);
  const [done, setDone] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const { data: body, error: problem } = await api.GET("/schools/{school}/attendance/absences", { params: { path: { school: code }, query: { date } } });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (body) {
        setError(null);
        setData(body.data);
        setSelected(new Set());
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code, date]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat daftar untuk tanggal terpilih
    void load();
  }, [load]);

  const byClass = useMemo(() => {
    const groups = new Map<string, NonNullable<typeof data>["items"]>();
    for (const item of data?.items ?? []) {
      const key = item.class_name ?? "Tanpa kelas";
      groups.set(key, [...(groups.get(key) ?? []), item]);
    }
    return [...groups.entries()];
  }, [data]);

  function toggle(ids: string[], on: boolean) {
    setSelected((prev) => {
      const next = new Set(prev);
      for (const id of ids) {
        if (on) next.add(id);
        else next.delete(id);
      }
      return next;
    });
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (action === null) return;
    setPending(true);
    setError(null);
    setDone(null);
    try {
      const path = { school: code };
      let problem: unknown;
      if (action === "verify") {
        ({ error: problem } = await api.POST("/schools/{school}/attendance/absences/verify", { params: { path }, body: { date, student_ids: [...selected], reason: reason.trim() } }));
        if (problem === undefined) setDone(`${selected.size} siswa diverifikasi sebagai alpa.`);
      } else if (action === "hold") {
        ({ error: problem } = await api.POST("/schools/{school}/attendance/days/{date}/disruption", { params: { path: { ...path, date } }, body: { reason: reason.trim() } }));
        if (problem === undefined) setDone("Hari ditandai bergangguan; aturan alpa ditahan.");
      } else {
        ({ error: problem } = await api.POST("/schools/{school}/attendance/days/{date}/release", { params: { path: { ...path, date } }, body: { reason: reason.trim() } }));
        if (problem === undefined) setDone("Penahanan dilepas; aturan alpa dijalankan.");
      }
      if (problem !== undefined) {
        setError(toFormFailure(problem).message);
        return;
      }
      setAction(null);
      setReason("");
      await load();
    } catch (e) {
      setError(toFormFailure(e).message);
    } finally {
      setPending(false);
    }
  }

  const held = data?.health.status === "perlu_pemeriksaan";

  return (
    <section aria-labelledby="absence-title" className={CARD}>
      <div>
        <h2 id="absence-title" className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
          Verifikasi alpa
        </h2>
        <p className="text-sm text-zinc-600 dark:text-zinc-400">Periksa daftar alpa sementara, lalu verifikasi yang benar-benar tidak hadir. Yang sudah hadir akan tergantikan otomatis oleh scan.</p>
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <TextField label="Tanggal" name="absence-date" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
        {canHold && action === null ? (
          held ? (
            <SecondaryButton type="button" onClick={() => setAction("release")}>
              Lepas penahanan
            </SecondaryButton>
          ) : (
            <SecondaryButton type="button" onClick={() => setAction("hold")}>
              Tandai hari bergangguan
            </SecondaryButton>
          )
        ) : null}
      </div>

      {held ? (
        <div role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
          <p className="font-medium">Aturan alpa ditahan untuk hari ini; siswa tetap berstatus belum tercatat.</p>
          <ul className="list-disc pl-5">
            {data?.health.reasons.map((r) => (
              <li key={r}>{HEALTH_REASONS[r] ?? r}</li>
            ))}
          </ul>
        </div>
      ) : null}

      {done ? <FormAlert tone="success">{done}</FormAlert> : null}
      {error ? <FormAlert>{error}</FormAlert> : null}

      {data !== null && data.items.length === 0 && !held ? <p className="text-sm text-zinc-600 dark:text-zinc-400">Tidak ada alpa sementara pada tanggal ini.</p> : null}

      {byClass.map(([className, items]) => {
        const ids = items.map((i) => i.student_id);
        const all = ids.every((id) => selected.has(id));
        return (
          <fieldset key={className} className="space-y-1">
            <legend className="mb-1 flex items-center gap-3 text-sm font-medium text-zinc-700 dark:text-zinc-300">
              <span>{className}</span>
              <label className="flex items-center gap-1 text-xs font-normal">
                <input type="checkbox" checked={all} onChange={(e) => toggle(ids, e.target.checked)} />
                <span>Pilih semua</span>
              </label>
            </legend>
            <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
              {items.map((item) => (
                <li key={item.student_id} className="py-1.5 text-sm">
                  <label className="flex items-center gap-2">
                    <input type="checkbox" checked={selected.has(item.student_id)} onChange={(e) => toggle([item.student_id], e.target.checked)} />
                    <span className="text-zinc-900 dark:text-zinc-100">{item.student_name}</span>
                    <span className="text-zinc-500">{item.student_number}</span>
                  </label>
                </li>
              ))}
            </ul>
          </fieldset>
        );
      })}

      {selected.size > 0 && action === null ? (
        <PrimaryButton type="button" onClick={() => setAction("verify")}>
          Verifikasi {selected.size} sebagai alpa
        </PrimaryButton>
      ) : null}

      {action !== null ? (
        <form noValidate onSubmit={submit} className="max-w-lg space-y-3">
          <TextAreaField
            label={action === "verify" ? "Alasan verifikasi (min. 10 karakter)" : "Alasan (min. 10 karakter)"}
            name="absence-reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            maxLength={500}
          />
          <div className="flex gap-3">
            <PrimaryButton type="submit" pending={pending} disabled={reason.trim().length < 10}>
              {action === "verify" ? "Verifikasi alpa" : action === "hold" ? "Tandai bergangguan" : "Lepas penahanan"}
            </PrimaryButton>
            <SecondaryButton
              type="button"
              onClick={() => {
                setAction(null);
                setReason("");
              }}
            >
              Batal
            </SecondaryButton>
          </div>
        </form>
      ) : null}
    </section>
  );
}
