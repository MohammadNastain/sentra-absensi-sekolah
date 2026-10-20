"use client";

import type { Schemas } from "@sentra/api-client";
import Link from "next/link";
import { useEffect, useState } from "react";
import { FormAlert, SelectField, Skeleton } from "@/components/ui";
import { api } from "@/lib/api";
import { toFormFailure } from "@/lib/errors";
import { formatDate } from "@/lib/format";
import { schoolToday } from "./attendance/absence-panels";
import { useSchool } from "./school-context";

type Point = Schemas["ReportTrendPoint"];

const W = 640;
const H = 180;
const PAD = { l: 36, r: 12, t: 12, b: 24 };

function shiftDays(date: string, days: number): string {
  const d = new Date(`${date}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);

  return d.toISOString().slice(0, 10);
}

/** Tren persentase kehadiran harian (SVG tanpa pustaka) dan ringkasan alpa yang menunggu verifikasi. */
export function AttendanceTrend() {
  const { code, can, profile } = useSchool();
  const [span, setSpan] = useState("14");
  const [points, setPoints] = useState<Point[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    const to = schoolToday(profile.timezone);
    const from = shiftDays(to, -(Number(span) - 1));
    api
      .GET("/schools/{school}/reports/trend", { params: { path: { school: code }, query: { from, to } } })
      .then(({ data, error: problem }) => {
        if (cancelled) return;
        if (problem !== undefined) setError(toFormFailure(problem).message);
        else if (data) {
          setError(null);
          setPoints(data.data);
        }
      })
      .catch((e) => {
        if (!cancelled) setError(toFormFailure(e).message);
      });
    return () => {
      cancelled = true;
    };
  }, [code, profile.timezone, span]);

  if (error && !points) return <FormAlert>{error}</FormAlert>;
  if (!points) {
    return (
      <div role="status" aria-label="Memuat tren kehadiran">
        <Skeleton className="h-80" />
      </div>
    );
  }

  const pending = points.reduce((sum, p) => sum + p.alpa_belum_diverifikasi, 0);
  const plotted = points.filter((p) => p.attendance_percentage !== null);
  const x = (i: number) => PAD.l + (points.length <= 1 ? (W - PAD.l - PAD.r) / 2 : (i * (W - PAD.l - PAD.r)) / (points.length - 1));
  const y = (pct: number) => PAD.t + ((100 - pct) * (H - PAD.t - PAD.b)) / 100;
  const line = points
    .map((p, i) => (p.attendance_percentage === null ? null : `${x(i).toFixed(1)},${y(p.attendance_percentage).toFixed(1)}`))
    .filter((v): v is string => v !== null)
    .join(" ");
  const firstIdx = points.findIndex((p) => p.attendance_percentage !== null);
  const lastIdx = points.length - 1 - [...points].reverse().findIndex((p) => p.attendance_percentage !== null);
  const area = `${x(firstIdx).toFixed(1)},${y(0).toFixed(1)} ${line} ${x(lastIdx).toFixed(1)},${y(0).toFixed(1)}`;

  return (
    <section aria-labelledby="trend-title" className="space-y-4 rounded-2xl border border-zinc-200/70 bg-white p-6 shadow-card dark:border-zinc-800 dark:bg-zinc-900">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h2 id="trend-title" className="text-lg font-bold text-zinc-900 dark:text-zinc-50">
          Tren kehadiran
        </h2>
        <div className="w-40">
          <SelectField label="Rentang" name="trend-span" value={span} onChange={(e) => setSpan(e.target.value)}>
            <option value="14">14 hari</option>
            <option value="30">30 hari</option>
            <option value="90">90 hari</option>
          </SelectField>
        </div>
      </div>
      {error ? <FormAlert>{error}</FormAlert> : null}

      {pending > 0 && can("school.attendance.verify") ? (
        <p className="text-sm text-amber-700 dark:text-amber-400">
          {pending} alpa sementara menunggu verifikasi.{" "}
          <Link className="font-medium underline" href={`/schools/${code}/attendance`}>
            Buka verifikasi
          </Link>
        </p>
      ) : null}

      {plotted.length === 0 ? (
        <p className="text-sm text-zinc-600 dark:text-zinc-400">Belum ada data kehadiran pada rentang ini.</p>
      ) : (
        <>
          <svg role="img" aria-label={`Grafik persentase kehadiran ${points.length} hari terakhir`} viewBox={`0 0 ${W} ${H}`} className="h-auto w-full text-emerald-600 dark:text-emerald-400">
            <defs>
              <linearGradient id="trend-area" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="currentColor" stopOpacity="0.28" />
                <stop offset="100%" stopColor="currentColor" stopOpacity="0" />
              </linearGradient>
            </defs>
            {[0, 50, 100].map((g) => (
              <g key={g}>
                <line x1={PAD.l} x2={W - PAD.r} y1={y(g)} y2={y(g)} strokeDasharray="3 5" className="stroke-zinc-200 dark:stroke-zinc-800" />
                <text x={PAD.l - 6} y={y(g) + 4} textAnchor="end" className="fill-zinc-500 text-[10px]">
                  {g}%
                </text>
              </g>
            ))}
            <polygon points={`${area}`} fill="url(#trend-area)" className="animate-fade [animation-delay:500ms]" />
            <polyline
              key={`${span}-${line}`}
              points={line}
              fill="none"
              stroke="currentColor"
              strokeWidth={2.5}
              strokeLinecap="round"
              strokeLinejoin="round"
              pathLength={1}
              strokeDasharray={1}
              className="animate-draw"
            />
            {points.map((p, i) =>
              p.attendance_percentage === null ? null : (
                <circle key={p.date} cx={x(i)} cy={y(p.attendance_percentage)} r={3.5} fill="white" stroke="currentColor" strokeWidth={2} className="animate-pop transition-[r] hover:[r:5.5px]" style={{ animationDelay: `${400 + i * 30}ms` }}>
                  <title>{`${formatDate(p.date)}: ${p.attendance_percentage.toFixed(2)}%`}</title>
                </circle>
              ),
            )}
            <text x={PAD.l} y={H - 6} className="fill-zinc-500 text-[10px]">
              {formatDate(points[0].date)}
            </text>
            <text x={W - PAD.r} y={H - 6} textAnchor="end" className="fill-zinc-500 text-[10px]">
              {formatDate(points[points.length - 1].date)}
            </text>
          </svg>
          <details>
            <summary className="cursor-pointer text-sm text-zinc-600 dark:text-zinc-400">Lihat data tabel</summary>
            <table className="mt-2 min-w-full text-sm">
              <thead>
                <tr className="text-zinc-500">
                  <th className="px-2 py-1 text-left">Tanggal</th>
                  <th className="px-2 py-1 text-right">Hadir + terlambat</th>
                  <th className="px-2 py-1 text-right">Alpa</th>
                  <th className="px-2 py-1 text-right">%</th>
                </tr>
              </thead>
              <tbody>
                {points.map((p) => (
                  <tr key={p.date} className="border-t border-zinc-100 dark:border-zinc-800">
                    <td className="px-2 py-1">{formatDate(p.date)}</td>
                    <td className="px-2 py-1 text-right tabular-nums">{p.present}</td>
                    <td className="px-2 py-1 text-right tabular-nums">{p.alpa + p.alpa_belum_diverifikasi}</td>
                    <td className="px-2 py-1 text-right tabular-nums">{p.attendance_percentage === null ? "—" : p.attendance_percentage.toFixed(2)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </details>
        </>
      )}
    </section>
  );
}
