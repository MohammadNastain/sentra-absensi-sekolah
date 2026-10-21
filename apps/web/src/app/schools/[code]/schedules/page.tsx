"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { Dialog } from "@/components/Dialog";
import { IconEdit } from "@/components/icons";
import { FormAlert, PrimaryButton, RowButton, SecondaryButton, SelectField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { fetchAll } from "@/lib/fetch-all";
import { formatDate } from "@/lib/format";
import { useSchool } from "../school-context";

type Schedule = Schemas["Schedule"];
type SchoolClass = Schemas["SchoolClass"];
type Scope = Schemas["ScheduleScope"];

const DAYS = [
  { value: 1, label: "Senin", short: "Sen" },
  { value: 2, label: "Selasa", short: "Sel" },
  { value: 3, label: "Rabu", short: "Rab" },
  { value: 4, label: "Kamis", short: "Kam" },
  { value: 5, label: "Jumat", short: "Jum" },
  { value: 6, label: "Sabtu", short: "Sab" },
  { value: 7, label: "Minggu", short: "Min" },
];

const SCOPE_LABEL: Record<Scope, string> = { school: "Seluruh sekolah", grade_level: "Per tingkat", class: "Per kelas" };

/** Baris jadwal per hari digabung menjadi satu "versi" bila semua isinya sama kecuali hari. */
type Version = { key: string; sample: Schedule; days: number[]; ids: string[] };

function groupVersions(rows: Schedule[]): Version[] {
  const map = new Map<string, Version>();
  for (const row of rows) {
    const key = [
      row.scope_type, row.grade_level, row.class?.id, row.effective_from, row.effective_until, row.is_active,
      row.check_in_start, row.check_in_deadline, row.late_grace_minutes, row.check_out_start, row.scheduled_check_out,
    ].join("|");
    const existing = map.get(key);
    if (existing) {
      existing.days.push(row.day_of_week);
      existing.ids.push(row.id);
    } else map.set(key, { key, sample: row, days: [row.day_of_week], ids: [row.id] });
  }
  return [...map.values()];
}

function scopeLabel(s: Schedule): string {
  if (s.scope_type === "grade_level") return `Tingkat ${s.grade_level}`;
  if (s.scope_type === "class") return `Kelas ${s.class?.name ?? ""}`;
  return SCOPE_LABEL.school;
}

function daysLabel(days: number[]): string {
  return [...days].sort().map((d) => DAYS[d - 1].short).join(", ");
}

function todayLocal(): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

export default function SchedulesPage() {
  const { code, can } = useSchool();
  const canManage = can("school.schedule.manage");
  const [rows, setRows] = useState<Schedule[] | null>(null);
  const [scope, setScope] = useState<Scope | "">("");
  const [error, setError] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<Version | null>(null);
  // Versi baru yang kolomnya diisi dari versi lama (dipakai bila jam jadwal lama sudah terkunci).
  const [template, setTemplate] = useState<Version | null>(null);
  const [toggling, setToggling] = useState<string | null>(null);

  async function toggleActive(version: Version) {
    setToggling(version.key);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      for (const id of version.ids) {
        const result = await api.PATCH("/schools/{school}/schedules/{schedule}", {
          params: { path: { school: code, schedule: id } },
          body: { is_active: !version.sample.is_active },
        });
        if (result.error !== undefined) return result;
      }
      return { error: undefined };
    });
    setToggling(null);
    if (!outcome.ok) setError(outcome.failure.message);
    else void load();
  }

  const load = useCallback(async () => {
    try {
      const all = await fetchAll(async (page) => {
        const { data, error: problem } = await api.GET("/schools/{school}/schedules", {
          params: { path: { school: code }, query: { page, ...(scope ? { scope_type: scope } : {}) } },
        });
        if (problem !== undefined) throw problem;
        return data;
      });
      setError(null);
      setRows(all);
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code, scope]);

  useEffect(() => {
    void load();
  }, [load]);

  const versions = rows ? groupVersions(rows) : null;

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-50">Jadwal absensi</h1>
        {canManage ? <PrimaryButton onClick={() => setCreating(true)}>Terbitkan versi jadwal</PrimaryButton> : null}
      </div>
      <p className="text-sm text-zinc-600 dark:text-zinc-400">
        Salah isi jam? Klik «Ubah» untuk memperbaikinya. Jadwal yang sudah dipakai absensi tidak bisa diubah supaya riwayat tetap benar;
        untuk itu terbitkan versi baru yang berlaku mulai tanggal tertentu (versi sebelumnya otomatis berakhir sehari sebelumnya).
        Prioritas: jadwal kelas, lalu tingkat, lalu seluruh sekolah.
      </p>

      <div className="w-48">
        <SelectField label="Cakupan" name="scope_filter" value={scope} onChange={(event) => setScope(event.target.value as Scope | "")}>
          <option value="">Semua</option>
          {(Object.keys(SCOPE_LABEL) as Scope[]).map((s) => (
            <option key={s} value={s}>
              {SCOPE_LABEL[s]}
            </option>
          ))}
        </SelectField>
      </div>

      {error ? <FormAlert>{error}</FormAlert> : null}
      {versions === null && !error ? (
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      ) : null}
      {versions && versions.length === 0 ? <p className="text-zinc-600 dark:text-zinc-400">Belum ada jadwal.</p> : null}

      {versions && versions.length > 0 ? (
        <div className="overflow-x-auto rounded-2xl border border-zinc-200/70 bg-white shadow-card dark:border-zinc-800 dark:bg-zinc-900">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-zinc-200 bg-zinc-50/70 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950/40 dark:text-zinc-400">
              <tr>
                <th scope="col" className="px-4 py-3 font-semibold">Cakupan</th>
                <th scope="col" className="px-4 py-3 font-semibold">Hari</th>
                <th scope="col" className="px-4 py-3 font-semibold">Absen masuk</th>
                <th scope="col" className="px-4 py-3 font-semibold">Absen pulang</th>
                <th scope="col" className="px-4 py-3 font-semibold">Berlaku</th>
                <th scope="col" className="px-4 py-3 font-semibold">Status</th>
                {canManage ? <th scope="col" className="px-4 py-3 font-semibold">Aksi</th> : null}
              </tr>
            </thead>
            <tbody>
              {versions.map((version) => {
                const { key, sample, days } = version;
                return (
                <tr key={key} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                  <td className="px-4 py-3 font-semibold text-zinc-900 dark:text-zinc-100">{scopeLabel(sample)}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{daysLabel(days)}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                    {sample.check_in_start}–{sample.check_in_deadline} (+{sample.late_grace_minutes} mnt)
                  </td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                    {sample.check_out_start}–{sample.scheduled_check_out}
                  </td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                    {formatDate(sample.effective_from)} – {sample.effective_until ? formatDate(sample.effective_until) : "sekarang"}
                  </td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{sample.is_active ? "Aktif" : "Nonaktif"}</td>
                  {canManage ? (
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-x-3 gap-y-1">
                        <RowButton icon={<IconEdit width={14} height={14} />} onClick={() => setEditing(version)}>
                          Ubah
                        </RowButton>
                        <RowButton
                          disabled={toggling === key}
                          onClick={() => void toggleActive(version)}>
                          {sample.is_active ? "Nonaktifkan" : "Aktifkan"}
                        </RowButton>
                      </div>
                    </td>
                  ) : null}
                </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      ) : null}

      <Dialog open={editing !== null} title="Ubah jam jadwal" onClose={() => setEditing(null)}>
        {editing ? (
          <EditForm
            key={editing.key}
            code={code}
            version={editing}
            onCancel={() => setEditing(null)}
            onDone={() => {
              setEditing(null);
              void load();
            }}
            onPublishNew={() => {
              setTemplate(editing);
              setEditing(null);
              setCreating(true);
            }}
          />
        ) : null}
      </Dialog>

      <Dialog
        open={creating}
        title="Terbitkan versi jadwal"
        onClose={() => {
          setCreating(false);
          setTemplate(null);
        }}
      >
        {creating ? (
          <ScheduleForm
            code={code}
            canListClasses={can("school.academic.view")}
            template={template}
            onCancel={() => {
              setCreating(false);
              setTemplate(null);
            }}
            onDone={() => {
              setCreating(false);
              setTemplate(null);
              void load();
            }}
          />
        ) : null}
      </Dialog>
    </>
  );
}

function ScheduleForm({
  code,
  canListClasses,
  template,
  onCancel,
  onDone,
}: {
  code: string;
  canListClasses: boolean;
  template: Version | null;
  onCancel: () => void;
  onDone: () => void;
}) {
  const base = template?.sample;
  const [scope, setScope] = useState<Scope>(base?.scope_type ?? "school");
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  useEffect(() => {
    if (!canListClasses) return;
    let cancelled = false;
    fetchAll(async (page) => {
      const { data, error } = await api.GET("/schools/{school}/classes", {
        params: { path: { school: code }, query: { page, status: "active" } },
      });
      if (error !== undefined) throw error;
      return data;
    })
      .then((all) => {
        if (!cancelled) setClasses(all);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [canListClasses, code]);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const text = (name: string) => String(form.get(name) ?? "").trim();
    const days = form.getAll("days").map((d) => Number(d));
    const body: Schemas["SchedulePublish"] = {
      scope_type: scope,
      ...(scope === "grade_level" ? { grade_level: Number(text("grade_level")) } : {}),
      ...(scope === "class" ? { class_id: text("class_id") } : {}),
      days,
      check_in_start: text("check_in_start"),
      check_in_deadline: text("check_in_deadline"),
      late_grace_minutes: Number(text("late_grace_minutes")),
      check_out_start: text("check_out_start"),
      scheduled_check_out: text("scheduled_check_out"),
      effective_from: text("effective_from"),
    };
    setPending(true);
    setFailure(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/schools/{school}/schedules", { params: { path: { school: code } }, body });
    });
    setPending(false);

    if (!outcome.ok) setFailure(outcome.failure);
    else onDone();
  }

  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}

      <SelectField label="Cakupan" name="scope_type" value={scope} onChange={(event) => setScope(event.target.value as Scope)} error={failure?.fields.scope_type}>
        {(Object.keys(SCOPE_LABEL) as Scope[])
          .filter((s) => s !== "class" || canListClasses)
          .map((s) => (
            <option key={s} value={s}>
              {SCOPE_LABEL[s]}
            </option>
          ))}
      </SelectField>
      {scope === "grade_level" ? (
        <SelectField label="Tingkat" name="grade_level" defaultValue={String(base?.grade_level ?? 7)} error={failure?.fields.grade_level}>
          {Array.from({ length: 13 }, (_, i) => i + 1).map((g) => (
            <option key={g} value={g}>
              {g}
            </option>
          ))}
        </SelectField>
      ) : null}
      {scope === "class" ? (
        <SelectField label="Kelas" name="class_id" defaultValue={base?.class?.id ?? ""} error={failure?.fields.class_id} required>
          <option value="" disabled>
            Pilih kelas
          </option>
          {classes.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name} ({c.academic_year.name})
            </option>
          ))}
        </SelectField>
      ) : null}

      <fieldset>
        <legend className="text-sm font-medium text-zinc-800 dark:text-zinc-200">Hari</legend>
        <div className="mt-1 flex flex-wrap gap-3">
          {DAYS.map((day) => (
            <label key={day.value} className="flex items-center gap-1.5 text-sm text-zinc-800 dark:text-zinc-200">
              <input type="checkbox" name="days" value={day.value} defaultChecked={template ? template.days.includes(day.value) : day.value <= 5} />
              {day.label}
            </label>
          ))}
        </div>
        {failure?.fields.days ? <p className="mt-1 text-sm text-red-700 dark:text-red-400">{failure.fields.days}</p> : null}
      </fieldset>

      <div className="grid grid-cols-2 gap-3">
        <TextField label="Mulai absen masuk" name="check_in_start" type="time" defaultValue={base?.check_in_start ?? "06:00"} error={failure?.fields.check_in_start} required />
        <TextField label="Batas tepat waktu" name="check_in_deadline" type="time" defaultValue={base?.check_in_deadline ?? "07:00"} error={failure?.fields.check_in_deadline} required />
        <TextField label="Mulai absen pulang" name="check_out_start" type="time" defaultValue={base?.check_out_start ?? "13:00"} error={failure?.fields.check_out_start} required />
        <TextField label="Jam pulang" name="scheduled_check_out" type="time" defaultValue={base?.scheduled_check_out ?? "14:00"} error={failure?.fields.scheduled_check_out} required />
      </div>
      <TextField
        label="Toleransi keterlambatan (menit)"
        name="late_grace_minutes"
        type="number"
        defaultValue={base?.late_grace_minutes ?? 10}
        error={failure?.fields.late_grace_minutes}
        required
      />
      <TextField label="Berlaku mulai" name="effective_from" type="date" defaultValue={todayLocal()} error={failure?.fields.effective_from} required />

      <div className="flex justify-end gap-2">
        <SecondaryButton type="button" onClick={onCancel}>
          Batal
        </SecondaryButton>
        <PrimaryButton type="submit" pending={pending}>
          Terbitkan
        </PrimaryButton>
      </div>
    </form>
  );
}

/** Koreksi jam satu versi (semua harinya sekaligus). Bila sudah dipakai absensi, tawarkan versi baru. */
function EditForm({
  code,
  version,
  onCancel,
  onDone,
  onPublishNew,
}: {
  code: string;
  version: Version;
  onCancel: () => void;
  onDone: () => void;
  onPublishNew: () => void;
}) {
  const { sample } = version;
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const text = (name: string) => String(form.get(name) ?? "").trim();
    const body: Schemas["ScheduleUpdate"] = {
      check_in_start: text("check_in_start"),
      check_in_deadline: text("check_in_deadline"),
      late_grace_minutes: Number(text("late_grace_minutes")),
      check_out_start: text("check_out_start"),
      scheduled_check_out: text("scheduled_check_out"),
    };
    setPending(true);
    setFailure(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      for (const id of version.ids) {
        const result = await api.PATCH("/schools/{school}/schedules/{schedule}", {
          params: { path: { school: code, schedule: id } },
          body,
        });
        if (result.error !== undefined) return result;
      }
      return { error: undefined };
    });
    setPending(false);

    if (!outcome.ok) setFailure(outcome.failure);
    else onDone();
  }

  const locked = failure?.message.includes("sudah dipakai") ?? false;

  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      <p className="text-sm text-zinc-600 dark:text-zinc-400">
        {scopeLabel(sample)} · {daysLabel(version.days)} · berlaku {formatDate(sample.effective_from)}
      </p>
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      <div className="grid grid-cols-2 gap-3">
        <TextField label="Mulai absen masuk" name="check_in_start" type="time" defaultValue={sample.check_in_start} error={failure?.fields.check_in_start} required />
        <TextField label="Batas tepat waktu" name="check_in_deadline" type="time" defaultValue={sample.check_in_deadline} error={failure?.fields.check_in_deadline} required />
        <TextField label="Mulai absen pulang" name="check_out_start" type="time" defaultValue={sample.check_out_start} error={failure?.fields.check_out_start} required />
        <TextField label="Jam pulang" name="scheduled_check_out" type="time" defaultValue={sample.scheduled_check_out} error={failure?.fields.scheduled_check_out} required />
      </div>
      <TextField label="Toleransi keterlambatan (menit)" name="late_grace_minutes" type="number" defaultValue={sample.late_grace_minutes} error={failure?.fields.late_grace_minutes} required />
      <div className="flex flex-wrap justify-end gap-2">
        <SecondaryButton type="button" onClick={onCancel}>
          Batal
        </SecondaryButton>
        {locked ? (
          <SecondaryButton type="button" onClick={onPublishNew}>
            Terbitkan versi baru
          </SecondaryButton>
        ) : null}
        <PrimaryButton type="submit" pending={pending}>
          Simpan
        </PrimaryButton>
      </div>
    </form>
  );
}
