"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { FormAlert } from "@/components/ui";
import { api } from "@/lib/api";
import { toFormFailure } from "@/lib/errors";
import { formatDate } from "@/lib/format";
import { useSchool } from "./school-context";

type Today = Schemas["AttendanceTodayEnvelope"]["data"];

const POLL_MS = 10_000;

/** Ringkasan absensi hari ini; memoll tiap 10 detik selama halaman terbuka. */
export function AttendanceToday() {
  const { code } = useSchool();
  const [today, setToday] = useState<Today | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/attendance/today", { params: { path: { school: code } } });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) {
        setError(null);
        setToday(data.data);
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat ringkasan awal
    void load();
    const timer = setInterval(() => void load(), POLL_MS);
    return () => clearInterval(timer);
  }, [load]);

  if (error && !today) return <FormAlert>{error}</FormAlert>;
  if (!today) {
    return (
      <p role="status" className="text-zinc-600 dark:text-zinc-400">
        Memuat absensi hari ini…
      </p>
    );
  }

  const schoolDay = today.day.kind === "school_day";

  return (
    <section aria-labelledby="attendance-today-title" className="space-y-4 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="attendance-today-title" className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
          Absensi hari ini
        </h2>
        <p className="text-sm text-zinc-600 dark:text-zinc-400">{formatDate(today.date)}</p>
      </div>

      {error ? <FormAlert>{error}</FormAlert> : null}
      {today.day.kind === "holiday" ? <FormAlert tone="success">Hari libur{today.day.holiday_name ? `: ${today.day.holiday_name}` : ""}. Tidak ada sesi absensi.</FormAlert> : null}
      {today.day.kind === "no_schedule" ? <FormAlert tone="success">Tidak ada jadwal absensi hari ini.</FormAlert> : null}
      {schoolDay && today.devices.active === 0 ? <FormAlert>Belum ada perangkat terminal yang terpasang.</FormAlert> : null}
      {schoolDay && today.devices.offline > 0 ? (
        <FormAlert>
          {today.devices.offline} dari {today.devices.active} perangkat terminal tidak terhubung. Scan di perangkat itu tidak akan tercatat.
        </FormAlert>
      ) : null}

      <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Stat label="Hadir tepat waktu" value={today.totals.on_time} tone="text-emerald-700 dark:text-emerald-400" />
        <Stat label="Terlambat" value={today.totals.late} tone="text-amber-700 dark:text-amber-400" />
        <Stat label="Belum tercatat" value={today.totals.not_recorded} tone="text-zinc-900 dark:text-zinc-50" note={`dari ${today.totals.students} siswa`} />
        <Stat label="Sudah pulang" value={today.totals.checked_out} tone="text-sky-700 dark:text-sky-400" />
      </dl>

      <div>
        <h3 className="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">Transaksi terbaru</h3>
        {today.recent.length === 0 ? (
          <p className="text-sm text-zinc-600 dark:text-zinc-400">Belum ada transaksi hari ini.</p>
        ) : (
          <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {today.recent.map((row, index) => (
              <li key={index} className="flex items-center justify-between gap-3 py-2 text-sm">
                <span>
                  <span className="font-medium text-zinc-900 dark:text-zinc-100">{row.student_name}</span>
                  <span className="ml-2 text-zinc-500">{row.class_name}</span>
                </span>
                <span className="text-right text-zinc-700 dark:text-zinc-300">
                  <span className="font-mono">{row.recorded_at_local}</span> · {describe(row)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}

function describe(row: Today["recent"][number]): string {
  if (row.result === "needs_review") return "Perlu tinjauan";
  if (row.event_type === "check_out") return "Pulang";
  return row.is_late ? "Masuk (terlambat)" : "Masuk";
}

function Stat({ label, value, tone, note }: { label: string; value: number; tone: string; note?: string }) {
  return (
    <div className="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-950">
      <dt className="text-sm text-zinc-600 dark:text-zinc-400">{label}</dt>
      <dd className={`mt-1 text-3xl font-semibold tabular-nums ${tone}`}>{value}</dd>
      {note ? <p className="text-xs text-zinc-500">{note}</p> : null}
    </div>
  );
}
