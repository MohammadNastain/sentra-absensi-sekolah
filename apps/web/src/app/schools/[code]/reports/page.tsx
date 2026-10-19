"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { FormAlert, PrimaryButton, SecondaryButton, SelectField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { toFormFailure } from "@/lib/errors";
import { fetchAll } from "@/lib/fetch-all";
import { formatDateTime } from "@/lib/format";
import { schoolToday } from "../attendance/absence-panels";
import { useSchool } from "../school-context";

type SchoolClass = Schemas["SchoolClass"];
type Metrics = Schemas["ReportMetrics"];
type ExportJob = Schemas["Export"];
type Report = Schemas["ExportRequestBody"]["report"];
type Format = Schemas["ExportRequestBody"]["format"];

const REPORTS: { value: Report; label: string }[] = [
  { value: "summary", label: "Rekap per kelas" },
  { value: "students", label: "Rekap per siswa" },
  { value: "lateness", label: "Keterlambatan" },
  { value: "daily", label: "Harian" },
  { value: "monthly", label: "Bulanan (per kelas)" },
  { value: "events", label: "Scan ditolak / perlu tinjauan" },
];
const REPORT_LABEL = Object.fromEntries(REPORTS.map((r) => [r.value, r.label]));
const STATUS_LABEL: Record<string, string> = { queued: "Mengantre", running: "Diproses", done: "Siap", failed: "Gagal" };
const CARD = "space-y-4 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900";
const CELL = "px-2 py-1 text-right tabular-nums";

type Row = { key: string; name: string; sub?: string; metrics: Metrics };

/** Laporan kehadiran: pratinjau rekap dan ekspor CSV/XLSX/PDF yang dibuat di latar belakang. */
export default function ReportsPage() {
  const { code, can, profile } = useSchool();
  const canListClasses = can("school.academic.view");
  const today = schoolToday(profile.timezone);
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [report, setReport] = useState<Report>("summary");
  const [from, setFrom] = useState(() => `${today.slice(0, 8)}01`);
  const [to, setTo] = useState(today);
  const [date, setDate] = useState(today);
  const [month, setMonth] = useState(today.slice(0, 7));
  const [classId, setClassId] = useState("");
  const [format, setFormat] = useState<Format>("xlsx");
  const [rows, setRows] = useState<Row[] | null>(null);
  const [lateRows, setLateRows] = useState<Schemas["ReportLatenessRow"][] | null>(null);
  const [exports, setExports] = useState<ExportJob[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  useEffect(() => {
    if (!canListClasses) return;
    let cancelled = false;
    fetchAll(async (page) => {
      const { data, error: problem } = await api.GET("/schools/{school}/classes", { params: { path: { school: code }, query: { page, status: "active" } } });
      if (problem !== undefined) throw problem;
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

  const loadExports = useCallback(async () => {
    try {
      const { data } = await api.GET("/schools/{school}/exports", { params: { path: { school: code } } });
      if (data) setExports(data.data);
    } catch {
      // daftar ekspor bersifat tambahan; kegagalan muat tidak mengganggu laporan
    }
  }, [code]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat daftar awal
    void loadExports();
  }, [loadExports]);

  const active = exports.some((e) => e.status === "queued" || e.status === "running");
  useEffect(() => {
    if (!active) return;
    const timer = setInterval(() => void loadExports(), 3000);
    return () => clearInterval(timer);
  }, [active, loadExports]);

  const rangeReport = report === "summary" || report === "students" || report === "lateness";
  const filters = (): Record<string, string> => {
    const f: Record<string, string> = report === "daily" ? { date } : report === "monthly" ? { month } : { from, to };
    if (classId !== "") f.class_id = classId;

    return f;
  };

  async function preview(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    setNotice(null);
    setRows(null);
    setLateRows(null);
    if (!rangeReport) {
      setError("Pratinjau tersedia untuk rekap kelas, rekap siswa, dan keterlambatan. Laporan ini dapat diekspor.");
      return;
    }
    setPending(true);
    try {
      const query = { from, to, ...(classId === "" ? {} : { class_id: classId }) };
      const params = { params: { path: { school: code }, query } };
      if (report === "summary") {
        const { data, error: problem } = await api.GET("/schools/{school}/reports/summary", params);
        if (problem !== undefined) return setError(toFormFailure(problem).message);
        setRows([...data.data.classes.map((c) => ({ key: c.class_id, name: c.class_name, metrics: c })), { key: "total", name: "Total", metrics: data.data.totals }]);
      } else if (report === "students") {
        const { data, error: problem } = await api.GET("/schools/{school}/reports/students", params);
        if (problem !== undefined) return setError(toFormFailure(problem).message);
        setRows(data.data.map((s) => ({ key: s.student_id, name: s.student_name, sub: s.student_number, metrics: s })));
      } else {
        const { data, error: problem } = await api.GET("/schools/{school}/reports/lateness", params);
        if (problem !== undefined) return setError(toFormFailure(problem).message);
        setLateRows(data.data);
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    } finally {
      setPending(false);
    }
  }

  async function requestExport() {
    setError(null);
    setNotice(null);
    setPending(true);
    try {
      const { error: problem } = await api.POST("/schools/{school}/exports", { params: { path: { school: code } }, body: { report, format, filters: filters() } });
      if (problem !== undefined) return setError(toFormFailure(problem).message);
      setNotice("Ekspor diminta. Berkas muncul di daftar di bawah saat siap.");
      await loadExports();
    } catch (e) {
      setError(toFormFailure(e).message);
    } finally {
      setPending(false);
    }
  }

  return (
    <>
      <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">Laporan kehadiran</h1>
      {notice ? <FormAlert tone="success">{notice}</FormAlert> : null}
      {error ? <FormAlert>{error}</FormAlert> : null}

      <section aria-labelledby="rep-filter-title" className={CARD}>
        <h2 id="rep-filter-title" className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
          Filter
        </h2>
        <form noValidate onSubmit={preview} className="grid max-w-3xl gap-4 sm:grid-cols-2">
          <SelectField label="Jenis laporan" name="report" value={report} onChange={(e) => setReport(e.target.value as Report)}>
            {REPORTS.map((r) => (
              <option key={r.value} value={r.value}>
                {r.label}
              </option>
            ))}
          </SelectField>
          {canListClasses ? (
            <SelectField label={report === "monthly" ? "Kelas (wajib)" : "Kelas"} name="class" value={classId} onChange={(e) => setClassId(e.target.value)}>
              <option value="">{report === "monthly" ? "Pilih kelas" : "Semua kelas"}</option>
              {classes.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </SelectField>
          ) : null}
          {report === "daily" ? <TextField label="Tanggal" name="date" type="date" value={date} onChange={(e) => setDate(e.target.value)} /> : null}
          {report === "monthly" ? <TextField label="Bulan" name="month" type="month" value={month} onChange={(e) => setMonth(e.target.value)} /> : null}
          {report !== "daily" && report !== "monthly" ? (
            <>
              <TextField label="Dari" name="from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
              <TextField label="Sampai" name="to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
            </>
          ) : null}
          <div className="flex flex-wrap items-end gap-3 sm:col-span-2">
            <PrimaryButton type="submit" pending={pending}>
              Tampilkan
            </PrimaryButton>
            <div className="w-40">
              <SelectField label="Format ekspor" name="format" value={format} onChange={(e) => setFormat(e.target.value as Format)}>
                <option value="xlsx">XLSX</option>
                <option value="csv">CSV</option>
                <option value="pdf">PDF</option>
              </SelectField>
            </div>
            <SecondaryButton type="button" onClick={() => void requestExport()} disabled={pending || (report === "monthly" && classId === "")}>
              Ekspor
            </SecondaryButton>
          </div>
        </form>
        <p className="text-xs text-zinc-500">
          Persentase kehadiran = (hadir + terlambat) ÷ (hari efektif − izin − sakit − dispensasi). Alpa sementara dan belum tercatat dilaporkan terpisah.
        </p>
      </section>

      {rows ? (
        <section aria-label="Hasil laporan" className={`${CARD} overflow-x-auto`}>
          {rows.length === 0 ? <p className="text-sm text-zinc-600 dark:text-zinc-400">Tidak ada data pada rentang ini.</p> : null}
          <table className="min-w-full text-sm">
            <thead>
              <tr className="text-zinc-500">
                <th className="px-2 py-1 text-left">{report === "summary" ? "Kelas" : "Siswa"}</th>
                {["Hari", "Hadir", "Terlambat", "Izin", "Sakit", "Disp.", "Alpa", "Alpa sem.", "Belum", "%"].map((h) => (
                  <th key={h} className="px-2 py-1 text-right">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.key} className={r.key === "total" ? "border-t font-semibold" : "border-t border-zinc-100 dark:border-zinc-800"}>
                  <td className="px-2 py-1">
                    {r.name}
                    {r.sub ? <span className="ml-2 text-zinc-500">{r.sub}</span> : null}
                  </td>
                  <td className={CELL}>{r.metrics.effective_days}</td>
                  <td className={CELL}>{r.metrics.hadir}</td>
                  <td className={CELL}>{r.metrics.terlambat}</td>
                  <td className={CELL}>{r.metrics.izin}</td>
                  <td className={CELL}>{r.metrics.sakit}</td>
                  <td className={CELL}>{r.metrics.dispensasi}</td>
                  <td className={CELL}>{r.metrics.alpa}</td>
                  <td className={CELL}>{r.metrics.alpa_belum_diverifikasi}</td>
                  <td className={CELL}>{r.metrics.belum_tercatat}</td>
                  <td className={CELL}>{r.metrics.attendance_percentage === null ? "—" : r.metrics.attendance_percentage.toFixed(2)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      ) : null}

      {lateRows ? (
        <section aria-label="Hasil laporan" className={`${CARD} overflow-x-auto`}>
          {lateRows.length === 0 ? <p className="text-sm text-zinc-600 dark:text-zinc-400">Tidak ada keterlambatan pada rentang ini.</p> : null}
          <ul className="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
            {lateRows.map((r) => (
              <li key={r.student_id} className="flex flex-wrap justify-between gap-2 py-2">
                <span>
                  <span className="font-medium text-zinc-900 dark:text-zinc-100">{r.student_name}</span>
                  <span className="ml-2 text-zinc-500">
                    {r.student_number} · {r.class_name}
                  </span>
                </span>
                <span className="text-zinc-700 dark:text-zinc-300">
                  {r.late_days} hari · {r.late_minutes} menit
                </span>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      <section aria-labelledby="rep-exports-title" className={CARD}>
        <h2 id="rep-exports-title" className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
          Ekspor saya
        </h2>
        {exports.length === 0 ? <p className="text-sm text-zinc-600 dark:text-zinc-400">Belum ada ekspor.</p> : null}
        <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
          {exports.map((e) => (
            <li key={e.id} className="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
              <span className="text-zinc-800 dark:text-zinc-200">
                {REPORT_LABEL[e.report] ?? e.report} · {e.format.toUpperCase()} · {formatDateTime(e.created_at, profile.timezone)}
              </span>
              <span className="flex items-center gap-3">
                <span className="text-zinc-500">{STATUS_LABEL[e.status] ?? e.status}</span>
                {e.status === "failed" ? <span className="text-red-600 dark:text-red-400">{e.error_message}</span> : null}
                {e.download_url ? (
                  <a className="font-medium text-blue-700 underline dark:text-blue-400" href={e.download_url} download>
                    Unduh
                  </a>
                ) : e.status === "done" ? (
                  <span className="text-zinc-500">Kedaluwarsa</span>
                ) : null}
              </span>
            </li>
          ))}
        </ul>
      </section>
    </>
  );
}
