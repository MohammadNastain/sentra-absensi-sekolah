"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState, type ComponentType, type SVGProps } from "react";
import { IconCheck, IconClock, IconDoc, IconLogout } from "@/components/icons";
import { CountUp, Ring } from "@/components/motion";
import { FormAlert, Skeleton } from "@/components/ui";
import { api } from "@/lib/api";
import { toFormFailure } from "@/lib/errors";
import { formatDate } from "@/lib/format";
import { useSchool } from "./school-context";

type Today = Schemas["AttendanceTodayEnvelope"]["data"];

const POLL_MS = 10_000;

const CARD = "rounded-2xl border border-zinc-200/70 bg-white p-6 shadow-card dark:border-zinc-800 dark:bg-zinc-900";

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
      <div role="status" aria-label="Memuat absensi hari ini" className="grid gap-6 xl:grid-cols-3">
        <Skeleton className="h-72 xl:col-span-2" />
        <Skeleton className="h-72" />
      </div>
    );
  }

  const schoolDay = today.day.kind === "school_day";
  const { students, on_time, late, not_recorded, checked_out } = today.totals;
  const present = on_time + late;
  const percent = students === 0 ? 0 : (present / students) * 100;

  return (
    <div className="grid gap-6 xl:grid-cols-3">
      <section aria-labelledby="attendance-today-title" className={`${CARD} space-y-5 xl:col-span-2`}>
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 id="attendance-today-title" className="text-lg font-bold text-zinc-900 dark:text-zinc-50">
            Absensi hari ini
          </h2>
          <p className="rounded-full bg-zinc-100 px-3 py-1 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{formatDate(today.date)}</p>
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

        <div className="flex flex-col items-center gap-6 sm:flex-row sm:items-center">
          <Ring percent={percent} size={148} stroke={13} label={`Kehadiran hari ini ${percent.toFixed(0)} persen`}>
            <span className="text-3xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">
              <CountUp value={percent} decimals={0} />%
            </span>
            <span className="text-[11px] font-medium text-zinc-500">hadir</span>
          </Ring>

          <dl className="grid w-full flex-1 grid-cols-2 gap-3">
            <Stat icon={IconCheck} label="Tepat waktu" value={on_time} tone="emerald" index={0} />
            <Stat icon={IconClock} label="Terlambat" value={late} tone="amber" index={1} />
            <Stat icon={IconDoc} label="Belum tercatat" value={not_recorded} tone="slate" note={`dari ${students} siswa`} index={2} />
            <Stat icon={IconLogout} label="Sudah pulang" value={checked_out} tone="sky" index={3} />
          </dl>
        </div>
      </section>

      <section aria-labelledby="recent-title" className={`${CARD} flex flex-col`}>
        <h2 id="recent-title" className="text-lg font-bold text-zinc-900 dark:text-zinc-50">
          Transaksi terbaru
        </h2>
        {today.recent.length === 0 ? (
          <p className="mt-4 flex-1 rounded-xl bg-zinc-50 p-4 text-sm text-zinc-600 dark:bg-zinc-950 dark:text-zinc-400">Belum ada transaksi hari ini.</p>
        ) : (
          <ol className="mt-4 space-y-2">
            {today.recent.map((row, index) => {
              const tone = toneOf(row);

              return (
                <li
                  key={`${row.recorded_at_local}-${row.student_name}-${index}`}
                  className="group flex animate-rise items-center gap-3 rounded-xl bg-zinc-50 px-3 py-2.5 transition-colors hover:bg-brand-50 dark:bg-zinc-950 dark:hover:bg-zinc-800"
                  style={{ animationDelay: `${index * 45}ms` }}
                >
                  <span className={`h-9 w-1 shrink-0 rounded-full ${tone.bar}`} />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold text-zinc-900 dark:text-zinc-100">{row.student_name}</span>
                    <span className="block truncate text-xs text-zinc-500">
                      {row.class_name} · {describe(row)}
                    </span>
                  </span>
                  <span className="font-mono text-xs font-medium text-zinc-600 dark:text-zinc-300">{row.recorded_at_local}</span>
                </li>
              );
            })}
          </ol>
        )}
      </section>
    </div>
  );
}

function describe(row: Today["recent"][number]): string {
  if (row.result === "needs_review") return "Perlu tinjauan";
  if (row.event_type === "check_out") return "Pulang";
  return row.is_late ? "Masuk (terlambat)" : "Masuk";
}

function toneOf(row: Today["recent"][number]): { bar: string } {
  if (row.result === "needs_review") return { bar: "bg-red-400" };
  if (row.event_type === "check_out") return { bar: "bg-sky-400" };
  return { bar: row.is_late ? "bg-amber-400" : "bg-emerald-500" };
}

const TONES = {
  emerald: { chip: "bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300", value: "text-emerald-800 dark:text-emerald-300" },
  amber: { chip: "bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300", value: "text-amber-800 dark:text-amber-300" },
  slate: { chip: "bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300", value: "text-zinc-900 dark:text-zinc-50" },
  sky: { chip: "bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300", value: "text-sky-800 dark:text-sky-300" },
} as const;

function Stat({
  icon: Icon,
  label,
  value,
  tone,
  note,
  index,
}: {
  icon: ComponentType<SVGProps<SVGSVGElement>>;
  label: string;
  value: number;
  tone: keyof typeof TONES;
  note?: string;
  index: number;
}) {
  const t = TONES[tone];

  return (
    <div
      className="animate-rise rounded-2xl bg-zinc-50 p-4 transition duration-200 hover:-translate-y-0.5 hover:shadow-card dark:bg-zinc-950"
      style={{ animationDelay: `${index * 80}ms` }}
    >
      <div className="flex items-center gap-2.5">
        <span className={`flex size-8 items-center justify-center rounded-lg ${t.chip}`}>
          <Icon width={17} height={17} />
        </span>
        <dt className="text-sm font-medium text-zinc-600 dark:text-zinc-400">{label}</dt>
      </div>
      <dd className={`mt-2 text-3xl font-bold tracking-tight ${t.value}`}>
        <CountUp value={value} />
      </dd>
      {note ? <p className="text-xs text-zinc-500">{note}</p> : null}
    </div>
  );
}
