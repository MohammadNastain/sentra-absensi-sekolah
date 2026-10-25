"use client";

import type { Schemas } from "@sentra/api-client";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";
import { AppShell } from "@/components/AppShell";
import { IconBuilding, IconGrad, IconPlus, IconUsers } from "@/components/icons";
import { CountUp, Reveal, Ring } from "@/components/motion";
import { FormAlert, PageHeader, Skeleton } from "@/components/ui";
import { api } from "@/lib/api";
import { toFormFailure } from "@/lib/errors";
import { formatDate } from "@/lib/format";
import { usePlatformNav } from "@/lib/platform-nav";
import { redirectFor, useMe } from "@/lib/use-me";

type Overview = Schemas["PlatformOverview"];

const STATUS_LABEL: Record<string, string> = {
  trial: "Uji coba",
  active: "Aktif",
  suspended: "Ditangguhkan",
  terminated: "Berhenti",
};

const STATUS_COLOR: Record<string, string> = {
  trial: "bg-sky-500",
  active: "bg-emerald-500",
  suspended: "bg-amber-500",
  terminated: "bg-zinc-400",
};

/** Dasbor Super Admin: angka platform, sebaran sekolah, dan tren kehadiran lintas sekolah. */
export default function PlatformDashboardPage() {
  const router = useRouter();
  const me = useMe();
  const nav = usePlatformNav();
  const [overview, setOverview] = useState<Overview | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    api
      .GET("/platform/overview")
      .then(({ data, error: problem }) => {
        if (cancelled) return;
        const target = redirectFor(problem);
        if (target) router.replace(target);
        else if (problem !== undefined) setError(toFormFailure(problem).message);
        else if (data) setOverview(data.data);
      })
      .catch((e) => {
        if (!cancelled) setError(toFormFailure(e).message);
      });
    return () => {
      cancelled = true;
    };
  }, [router]);

  return (
    <AppShell context="Platform" nav={nav} userLabel={me?.email} userName={me?.name}>
      <PageHeader
        title="Dasbor platform"
        description={overview ? `Ringkasan seluruh sekolah per ${formatDate(overview.as_of)}.` : "Ringkasan seluruh sekolah."}
        actions={
          <Link
            href="/platform/new"
            className="inline-flex items-center gap-2 rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800"
          >
            <IconPlus className="h-4 w-4" /> Sekolah baru
          </Link>
        }
      />

      {error ? <FormAlert>{error}</FormAlert> : null}

      {!overview && !error ? (
        <div role="status" aria-label="Memuat dasbor" className="space-y-4">
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            {[0, 1, 2, 3].map((i) => (
              <Skeleton key={i} className="h-28" />
            ))}
          </div>
          <Skeleton className="h-72" />
        </div>
      ) : null}

      {overview ? <Dashboard overview={overview} /> : null}
    </AppShell>
  );
}

function Dashboard({ overview }: { overview: Overview }) {
  const { totals } = overview;
  const recorded = overview.attendance_trend.reduce((sum, p) => sum + p.recorded, 0);
  const present = overview.attendance_trend.reduce((sum, p) => sum + p.present, 0);
  const average = recorded > 0 ? (present * 100) / recorded : null;

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <Reveal index={0}>
          <StatCard icon={<IconBuilding className="h-5 w-5" />} label="Sekolah" value={totals.schools} />
        </Reveal>
        <Reveal index={1}>
          <StatCard icon={<IconGrad className="h-5 w-5" />} label="Siswa aktif" value={totals.students} />
        </Reveal>
        <Reveal index={2}>
          <StatCard icon={<IconUsers className="h-5 w-5" />} label="Guru aktif" value={totals.teachers} />
        </Reveal>
        <Reveal index={3}>
          <StatCard icon={<IconUsers className="h-5 w-5" />} label="Akun pengguna" value={totals.users} />
        </Reveal>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Reveal index={4} className="lg:col-span-2">
          <TrendCard points={overview.attendance_trend} />
        </Reveal>
        <Reveal index={5}>
          <Card title="Rata-rata kehadiran" hint={`${overview.attendance_trend.length} hari terakhir`}>
            <div className="flex justify-center py-2">
              <Ring percent={average ?? 0} label={average === null ? "Belum ada data kehadiran" : `Rata-rata kehadiran ${average.toFixed(1)} persen`}>
                <span className="text-2xl font-bold text-zinc-900 dark:text-zinc-50">{average === null ? "—" : <CountUp value={average} decimals={1} />}</span>
                {average === null ? null : <span className="text-xs text-zinc-500">persen</span>}
              </Ring>
            </div>
            <p className="text-center text-xs text-zinc-500 dark:text-zinc-400">{recorded.toLocaleString("id-ID")} catatan kehadiran</p>
          </Card>
        </Reveal>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Reveal index={6}>
          <Card title="Status sekolah">
            <Bars rows={overview.schools_by_status.map((r) => ({ key: r.status, label: STATUS_LABEL[r.status] ?? r.status, total: r.total, color: STATUS_COLOR[r.status] ?? "bg-brand-500" }))} />
          </Card>
        </Reveal>
        <Reveal index={7}>
          <Card title="Jenjang pendidikan">
            {overview.schools_by_level.length === 0 ? (
              <Empty>Belum ada sekolah.</Empty>
            ) : (
              <Bars rows={overview.schools_by_level.map((r) => ({ key: r.level, label: r.level, total: r.total, color: "bg-brand-500" }))} />
            )}
          </Card>
        </Reveal>
        <Reveal index={8}>
          <Card title="Kehadiran hari ini" hint="per sekolah">
            {overview.schools_today.length === 0 ? (
              <Empty>Belum ada sekolah aktif.</Empty>
            ) : (
              <ul className="space-y-3">
                {overview.schools_today.map((s) => (
                  <li key={s.code}>
                    <div className="flex items-baseline justify-between gap-3 text-sm">
                      <Link href={`/platform/${s.code}`} className="truncate font-medium text-zinc-900 hover:text-brand-700 dark:text-zinc-100 dark:hover:text-brand-300">
                        {s.name}
                      </Link>
                      <span className="shrink-0 tabular-nums text-zinc-600 dark:text-zinc-400">{s.attendance_percentage === null ? "belum ada" : `${s.attendance_percentage.toFixed(1)}%`}</span>
                    </div>
                    <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                      <div className="h-full rounded-full bg-brand-500 transition-[width] duration-1000" style={{ width: `${s.attendance_percentage ?? 0}%` }} />
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </Reveal>
      </div>
    </div>
  );
}

function StatCard({ icon, label, value }: { icon: ReactNode; label: string; value: number }) {
  return (
    <div className="rounded-2xl border border-zinc-200/70 bg-white p-5 shadow-card dark:border-zinc-800 dark:bg-zinc-900">
      <div className="flex items-center justify-between text-zinc-500 dark:text-zinc-400">
        <span className="text-sm font-medium">{label}</span>
        <span className="rounded-lg bg-brand-50 p-2 text-brand-700 dark:bg-brand-950 dark:text-brand-300">{icon}</span>
      </div>
      <p className="mt-3 text-3xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">
        <CountUp value={value} />
      </p>
    </div>
  );
}

function Card({ title, hint, children }: { title: string; hint?: string; children: ReactNode }) {
  return (
    <section className="h-full space-y-4 rounded-2xl border border-zinc-200/70 bg-white p-6 shadow-card dark:border-zinc-800 dark:bg-zinc-900">
      <div className="flex items-baseline justify-between gap-2">
        <h2 className="text-lg font-bold text-zinc-900 dark:text-zinc-50">{title}</h2>
        {hint ? <span className="text-xs text-zinc-500 dark:text-zinc-400">{hint}</span> : null}
      </div>
      {children}
    </section>
  );
}

function Empty({ children }: { children: ReactNode }) {
  return <p className="text-sm text-zinc-600 dark:text-zinc-400">{children}</p>;
}

function Bars({ rows }: { rows: { key: string; label: string; total: number; color: string }[] }) {
  const max = Math.max(1, ...rows.map((r) => r.total));

  return (
    <ul className="space-y-3">
      {rows.map((r) => (
        <li key={r.key}>
          <div className="flex items-baseline justify-between text-sm">
            <span className="text-zinc-700 dark:text-zinc-300">{r.label}</span>
            <span className="font-semibold tabular-nums text-zinc-900 dark:text-zinc-100">{r.total}</span>
          </div>
          <div className="mt-1 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
            <div className={`h-full rounded-full ${r.color} transition-[width] duration-1000`} style={{ width: `${(r.total * 100) / max}%` }} />
          </div>
        </li>
      ))}
    </ul>
  );
}

const W = 640;
const H = 200;
const PAD = { l: 36, r: 12, t: 12, b: 24 };

/** Tren persentase kehadiran harian seluruh sekolah (SVG tanpa pustaka, gaya sama dengan dasbor sekolah). */
function TrendCard({ points }: { points: Overview["attendance_trend"] }) {
  const plotted = points.filter((p) => p.attendance_percentage !== null);
  const x = (i: number) => PAD.l + (points.length <= 1 ? (W - PAD.l - PAD.r) / 2 : (i * (W - PAD.l - PAD.r)) / (points.length - 1));
  const y = (pct: number) => PAD.t + ((100 - pct) * (H - PAD.t - PAD.b)) / 100;

  return (
    <Card title="Tren kehadiran" hint={`${points.length} hari terakhir, semua sekolah`}>
      {plotted.length === 0 ? (
        <Empty>Belum ada data kehadiran pada rentang ini.</Empty>
      ) : (
        <TrendChart points={points} x={x} y={y} />
      )}
    </Card>
  );
}

function TrendChart({ points, x, y }: { points: Overview["attendance_trend"]; x: (i: number) => number; y: (pct: number) => number }) {
  const line = points
    .map((p, i) => (p.attendance_percentage === null ? null : `${x(i).toFixed(1)},${y(p.attendance_percentage).toFixed(1)}`))
    .filter((v): v is string => v !== null)
    .join(" ");
  const firstIdx = points.findIndex((p) => p.attendance_percentage !== null);
  const lastIdx = points.length - 1 - [...points].reverse().findIndex((p) => p.attendance_percentage !== null);
  const area = `${x(firstIdx).toFixed(1)},${y(0).toFixed(1)} ${line} ${x(lastIdx).toFixed(1)},${y(0).toFixed(1)}`;

  return (
    <svg role="img" aria-label={`Grafik persentase kehadiran ${points.length} hari terakhir`} viewBox={`0 0 ${W} ${H}`} className="h-auto w-full text-brand-600 dark:text-brand-400">
      <defs>
        <linearGradient id="platform-trend-area" x1="0" y1="0" x2="0" y2="1">
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
      <polygon points={area} fill="url(#platform-trend-area)" className="animate-fade [animation-delay:500ms]" />
      <polyline points={line} fill="none" stroke="currentColor" strokeWidth={2.5} strokeLinecap="round" strokeLinejoin="round" pathLength={1} strokeDasharray={1} className="animate-draw" />
      {points.map((p, i) =>
        p.attendance_percentage === null ? null : (
          <circle key={p.date} cx={x(i)} cy={y(p.attendance_percentage)} r={3.5} fill="white" stroke="currentColor" strokeWidth={2} className="animate-pop transition-[r] hover:[r:5.5px]" style={{ animationDelay: `${400 + i * 30}ms` }}>
            <title>{`${formatDate(p.date)}: ${p.attendance_percentage.toFixed(2)}% (${p.present} dari ${p.recorded})`}</title>
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
  );
}
