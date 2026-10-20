"use client";

import type { Schemas } from "@sentra/api-client";
import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Pager } from "@/components/Pager";
import { FormAlert, PrimaryButton, SecondaryButton, SelectField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure } from "@/lib/errors";
import { formatDate } from "@/lib/format";
import { useSchool } from "../../school-context";

type Batch = Schemas["ImportBatch"];
type Row = Schemas["ImportRow"];
type RowFilter = Schemas["ImportRowStatus"] | "";

const STATUS_LABEL: Record<Schemas["ImportStatus"], string> = {
  uploaded: "Menunggu diproses",
  validating: "Sedang diperiksa",
  validated: "Siap ditinjau",
  committing: "Menyimpan",
  committed: "Selesai disimpan",
  failed: "Gagal",
  cancelled: "Dibatalkan",
};

const ROW_STATUS_LABEL: Record<Schemas["ImportRowStatus"], string> = {
  valid: "Valid",
  invalid: "Gagal",
  committed: "Tersimpan",
  skipped: "Dilewati",
};

const COLUMNS = ["nomor_induk", "nisn", "nama_lengkap", "tanggal_lahir", "kelas"] as const;

export default function StudentImportPage() {
  const { code, can } = useSchool();
  const canManage = can("school.students.manage");
  const [batch, setBatch] = useState<Batch | null>(null);
  const [history, setHistory] = useState<Batch[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const [committing, setCommitting] = useState(false);

  const loadHistory = useCallback(async () => {
    try {
      const { data } = await api.GET("/schools/{school}/imports", { params: { path: { school: code } } });
      if (data) setHistory(data.data);
    } catch {
      // Riwayat hanya pelengkap.
    }
  }, [code]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat riwayat impor
    if (canManage) void loadHistory();
  }, [canManage, loadHistory]);

  // Validasi berjalan di latar belakang: pantau sampai selesai atau gagal.
  const pendingId = batch && (batch.status === "uploaded" || batch.status === "validating") ? batch.id : null;
  useEffect(() => {
    if (!pendingId) return;
    const timer = setInterval(async () => {
      try {
        const { data } = await api.GET("/schools/{school}/imports/{import}", {
          params: { path: { school: code, import: pendingId } },
        });
        if (data) {
          setBatch(data.data);
          if (data.data.status !== "uploaded" && data.data.status !== "validating") void loadHistory();
        }
      } catch {
        // Coba lagi pada putaran berikutnya.
      }
    }, 1500);
    return () => clearInterval(timer);
  }, [pendingId, code, loadHistory]);

  if (!canManage) {
    return <FormAlert>Anda tidak memiliki izin untuk mengimpor siswa.</FormAlert>;
  }

  async function upload(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const file = new FormData(event.currentTarget).get("file");
    if (!(file instanceof File) || file.size === 0) {
      setError("Pilih file CSV atau XLSX terlebih dahulu.");
      return;
    }
    setUploading(true);
    setError(null);
    setBatch(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/schools/{school}/imports/students", {
        params: { path: { school: code } },
        // Multipart: file dikirim lewat FormData; nilai `body.file` hanya memenuhi tipe.
        body: { file: "" },
        bodySerializer: () => {
          const form = new FormData();
          form.append("file", file);
          return form;
        },
      });
    });
    setUploading(false);

    if (!outcome.ok) setError(outcome.failure.message);
    else if (outcome.result.data) setBatch(outcome.result.data.data);
  }

  async function commit() {
    if (!batch) return;
    setCommitting(true);
    setError(null);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/schools/{school}/imports/{import}/commit", { params: { path: { school: code, import: batch.id } } });
    });
    setCommitting(false);

    if (!outcome.ok) setError(outcome.failure.message);
    else if (outcome.result.data) {
      setBatch(outcome.result.data.data);
      void loadHistory();
    }
  }

  const base = `/api/v1/schools/${code}/imports`;

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-50">Impor siswa</h1>
        <Link href={`/schools/${code}/students`} className="text-sm font-medium text-brand-700 hover:underline dark:text-brand-400">
          Kembali ke daftar siswa
        </Link>
      </div>

      <section className="space-y-3 rounded-2xl border border-zinc-200/70 bg-white shadow-card p-4 dark:border-zinc-800 dark:bg-zinc-900">
        <p className="text-sm text-zinc-700 dark:text-zinc-300">
          Unggah file CSV atau XLSX (maksimal 5 MB dan 5.000 baris). Kolom wajib: <code>nomor_induk</code>,{" "}
          <code>nama_lengkap</code>. Kolom opsional: <code>nisn</code> (10 digit; format sel sebagai teks agar angka nol di depan tidak
          hilang), <code>tanggal_lahir</code> (TTTT-BB-HH), <code>kelas</code> (nama kelas aktif di tahun ajaran aktif).
        </p>
        <a href={`${base}/template`} className="inline-block text-sm font-medium text-brand-700 hover:underline dark:text-brand-400">
          Unduh templat CSV
        </a>
        <form onSubmit={upload} noValidate className="flex flex-wrap items-end gap-3">
          <div>
            <label htmlFor="import-file" className="block text-sm font-medium text-zinc-800 dark:text-zinc-200">
              File impor
            </label>
            <input
              id="import-file"
              name="file"
              type="file"
              accept=".csv,.xlsx"
              className="mt-1 block text-sm text-zinc-700 dark:text-zinc-300"
            />
          </div>
          <PrimaryButton type="submit" pending={uploading}>
            Unggah dan periksa
          </PrimaryButton>
        </form>
      </section>

      {error ? <FormAlert>{error}</FormAlert> : null}

      {batch ? <BatchPanel batch={batch} code={code} base={base} committing={committing} onCommit={commit} /> : null}

      {history.length > 0 ? (
        <section className="space-y-2">
          <h2 className="text-lg font-bold text-zinc-900 dark:text-zinc-50">Riwayat impor</h2>
          <div className="overflow-x-auto rounded-2xl border border-zinc-200/70 bg-white shadow-card dark:border-zinc-800 dark:bg-zinc-900">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-zinc-200 bg-zinc-50/70 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950/40 dark:text-zinc-400">
                <tr>
                  <th scope="col" className="px-4 py-3 font-semibold">File</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Diunggah</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Status</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Baris</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {history.map((item) => (
                  <tr key={item.id} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                    <td className="px-4 py-3 font-semibold text-zinc-900 dark:text-zinc-100">{item.original_filename}</td>
                    <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{formatDate(item.created_at.slice(0, 10))}</td>
                    <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{STATUS_LABEL[item.status]}</td>
                    <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                      {item.total_rows} (valid {item.valid_rows}, gagal {item.invalid_rows})
                    </td>
                    <td className="px-4 py-3">
                      <button
                        type="button"
                        className="font-medium text-brand-700 hover:underline dark:text-brand-400"
                        onClick={() => {
                          setError(null);
                          setBatch(item);
                        }}
                      >
                        Lihat
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      ) : null}
    </>
  );
}

function BatchPanel({
  batch,
  code,
  base,
  committing,
  onCommit,
}: {
  batch: Batch;
  code: string;
  base: string;
  committing: boolean;
  onCommit: () => void;
}) {
  const [rows, setRows] = useState<Row[] | null>(null);
  const [meta, setMeta] = useState<Schemas["PageMeta"] | null>(null);
  const [filter, setFilter] = useState<RowFilter>("invalid");
  const [page, setPage] = useState(1);
  const [rowsError, setRowsError] = useState<string | null>(null);

  const showRows = batch.status === "validated" || batch.status === "committed";

  useEffect(() => {
    if (!showRows) return;
    let cancelled = false;
    api
      .GET("/schools/{school}/imports/{import}/rows", {
        params: { path: { school: code, import: batch.id }, query: { page, ...(filter ? { status: filter } : {}) } },
      })
      .then(({ data, error }) => {
        if (cancelled) return;
        if (error !== undefined) setRowsError(toFormFailure(error).message);
        else if (data) {
          setRowsError(null);
          setRows(data.data);
          setMeta(data.meta);
        }
      })
      .catch((e) => {
        if (!cancelled) setRowsError(toFormFailure(e).message);
      });
    return () => {
      cancelled = true;
    };
  }, [showRows, code, batch.id, batch.status, filter, page]);

  return (
    <section aria-label="Hasil impor" className="space-y-4 rounded-2xl border border-zinc-200/70 bg-white shadow-card p-4 dark:border-zinc-800 dark:bg-zinc-900">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-lg font-bold text-zinc-900 dark:text-zinc-50">{batch.original_filename}</h2>
        <span role="status" className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
          {STATUS_LABEL[batch.status]}
        </span>
      </div>

      {batch.status === "failed" ? <FormAlert>{batch.error_message ?? "Impor gagal diproses."}</FormAlert> : null}

      {batch.status === "uploaded" || batch.status === "validating" ? (
        <p className="text-zinc-600 dark:text-zinc-400">Memeriksa file… Halaman ini diperbarui otomatis.</p>
      ) : null}

      {showRows ? (
        <>
          <p className="text-sm text-zinc-700 dark:text-zinc-300">
            {batch.status === "committed"
              ? `${batch.committed_rows} siswa berhasil disimpan; ${batch.invalid_rows} baris tidak disimpan.`
              : `${batch.total_rows} baris: ${batch.valid_rows} valid dan ${batch.invalid_rows} gagal. Hanya baris valid yang akan disimpan.`}
          </p>
          <div className="flex flex-wrap items-end gap-3">
            {batch.status === "validated" ? (
              <PrimaryButton type="button" pending={committing} disabled={batch.valid_rows === 0} onClick={onCommit}>
                Simpan {batch.valid_rows} siswa valid
              </PrimaryButton>
            ) : (
              <Link href={`/schools/${code}/students`}>
                <SecondaryButton type="button">Lihat daftar siswa</SecondaryButton>
              </Link>
            )}
            {batch.invalid_rows > 0 ? (
              <a
                href={`${base}/${batch.id}/failed-rows`}
                className="text-sm font-medium text-brand-700 hover:underline dark:text-brand-400"
              >
                Unduh baris gagal untuk diperbaiki
              </a>
            ) : null}
            <div className="ml-auto w-44">
              <SelectField
                label="Tampilkan baris"
                name="row_filter"
                value={filter}
                onChange={(event) => {
                  setFilter(event.target.value as RowFilter);
                  setPage(1);
                }}
              >
                <option value="">Semua</option>
                {(Object.keys(ROW_STATUS_LABEL) as Schemas["ImportRowStatus"][]).map((s) => (
                  <option key={s} value={s}>
                    {ROW_STATUS_LABEL[s]}
                  </option>
                ))}
              </SelectField>
            </div>
          </div>

          {rowsError ? <FormAlert>{rowsError}</FormAlert> : null}
          {rows && rows.length === 0 ? <p className="text-zinc-600 dark:text-zinc-400">Tidak ada baris.</p> : null}
          {rows && rows.length > 0 ? (
            <div className="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-zinc-200 bg-zinc-50/70 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950/40 dark:text-zinc-400">
                  <tr>
                    <th scope="col" className="px-3 py-2 font-medium">Baris</th>
                    {COLUMNS.map((c) => (
                      <th key={c} scope="col" className="px-3 py-2 font-medium">{c}</th>
                    ))}
                    <th scope="col" className="px-3 py-2 font-medium">Status</th>
                    <th scope="col" className="px-3 py-2 font-medium">Kesalahan</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.row_number} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                      <td className="px-3 py-2 text-zinc-700 dark:text-zinc-300">{row.row_number}</td>
                      {COLUMNS.map((c) => (
                        <td key={c} className="px-3 py-2 text-zinc-700 dark:text-zinc-300">{row.values[c] ?? ""}</td>
                      ))}
                      <td className="px-3 py-2 text-zinc-700 dark:text-zinc-300">{ROW_STATUS_LABEL[row.status]}</td>
                      <td className="px-3 py-2 text-red-700 dark:text-red-400">{row.errors.map((e) => e.message).join(" ")}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}
          {meta ? <Pager meta={meta} onPage={setPage} /> : null}
        </>
      ) : null}
    </section>
  );
}
