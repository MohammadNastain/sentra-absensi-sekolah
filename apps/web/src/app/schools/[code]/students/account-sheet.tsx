"use client";

import type { Schemas } from "@sentra/api-client";
import { PrimaryButton, SecondaryButton } from "@/components/ui";

export type StudentCredentials = Schemas["StudentCredentials"];
export interface AccountResult {
  created: StudentCredentials[];
  skipped: { student_id: string; reason: string }[];
}

const SKIP_REASON: Record<string, string> = {
  has_account: "sudah punya akun",
  inactive: "siswa tidak aktif",
  invalid_number: "nomor induk berisi karakter yang tidak didukung (hanya huruf, angka, titik, garis)",
  conflict: "nama pengguna sudah dipakai",
  not_found: "siswa tidak ditemukan",
};

/** Sel CSV aman: kutip ganda dan netralkan awalan rumus (=, +, -, @) agar tidak dieksekusi spreadsheet. */
function csvCell(value: string): string {
  const safe = /^[=+\-@\t\r]/.test(value) ? `'${value}` : value;
  return `"${safe.replaceAll('"', '""')}"`;
}

function toCsv(rows: StudentCredentials[]): string {
  const lines = [["Nama", "Nomor induk", "Nama pengguna", "Kata sandi awal"], ...rows.map((r) => [r.full_name, r.student_number, r.username, r.initial_password])];
  return lines.map((line) => line.map(csvCell).join(",")).join("\r\n");
}

function escapeHtml(value: string): string {
  return value.replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;");
}

/** Lembar kredensial cetak: satu kartu per siswa, dibuka di jendela baru agar hanya isi lembar yang tercetak. */
function printSheet(schoolName: string, rows: StudentCredentials[]): void {
  const cards = rows
    .map(
      (r) => `<div class="card"><strong>${escapeHtml(r.full_name)}</strong><br>Nama pengguna: <code>${escapeHtml(r.username)}</code><br>Kata sandi awal: <code>${escapeHtml(r.initial_password)}</code></div>`,
    )
    .join("");
  const win = window.open("", "_blank");
  if (!win) return;
  win.document.write(
    `<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Kredensial siswa</title><style>body{font-family:system-ui,sans-serif;margin:16px}h1{font-size:16px}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}.card{border:1px dashed #555;padding:10px;font-size:13px;break-inside:avoid}code{font-size:15px}</style></head><body><h1>${escapeHtml(schoolName)} — kredensial login siswa</h1><p>Kata sandi ini sementara; wajib diganti saat login pertama. Simpan lembar ini dengan aman.</p><div class="grid">${cards}</div></body></html>`,
  );
  win.document.close();
  win.focus();
  win.print();
}

function download(name: string, content: string): void {
  const url = URL.createObjectURL(new Blob([`﻿${content}`], { type: "text/csv;charset=utf-8" }));
  const link = document.createElement("a");
  link.href = url;
  link.download = name;
  link.click();
  URL.revokeObjectURL(url);
}

/**
 * Hasil pembuatan/atur ulang akun. Kata sandi awal hanya tampil di sini (server tidak menyimpannya terbaca), jadi
 * sheet ini satu-satunya kesempatan menyalin, mencetak, atau mengunduhnya.
 */
export function AccountSheet({
  result,
  names,
  schoolName,
  schoolCode,
  onClose,
}: {
  result: AccountResult;
  /** id siswa → nama, untuk menjelaskan siswa yang dilewati. */
  names: Record<string, string>;
  schoolName: string;
  schoolCode: string;
  onClose: () => void;
}) {
  return (
    <div className="space-y-4">
      {result.created.length > 0 ? (
        <>
          <p className="text-sm text-amber-800 dark:text-amber-300">
            Simpan kata sandi ini sekarang: tidak akan ditampilkan lagi. Siswa wajib menggantinya saat login pertama.
          </p>
          <div className="max-h-72 overflow-auto rounded-xl border border-zinc-200 dark:border-zinc-800">
            <table className="w-full text-left text-sm">
              <thead className="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-950/40">
                <tr>
                  <th scope="col" className="px-3 py-2">Nama</th>
                  <th scope="col" className="px-3 py-2">Nama pengguna</th>
                  <th scope="col" className="px-3 py-2">Kata sandi awal</th>
                </tr>
              </thead>
              <tbody>
                {result.created.map((r) => (
                  <tr key={r.student_id} className="border-t border-zinc-100 dark:border-zinc-800">
                    <td className="px-3 py-2">{r.full_name}</td>
                    <td className="px-3 py-2 font-mono">{r.username}</td>
                    <td className="px-3 py-2 font-mono">{r.initial_password}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="flex flex-wrap gap-2">
            <SecondaryButton type="button" onClick={() => printSheet(schoolName, result.created)}>
              Cetak lembar
            </SecondaryButton>
            <SecondaryButton type="button" onClick={() => download(`kredensial-siswa-${schoolCode}.csv`, toCsv(result.created))}>
              Unduh CSV
            </SecondaryButton>
          </div>
        </>
      ) : (
        <p className="text-sm text-zinc-700 dark:text-zinc-300">Tidak ada akun yang dibuat.</p>
      )}

      {result.skipped.length > 0 ? (
        <div>
          <p className="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Dilewati ({result.skipped.length}):</p>
          <ul className="mt-1 list-disc pl-5 text-sm text-zinc-600 dark:text-zinc-400">
            {result.skipped.map((s) => (
              <li key={s.student_id}>
                {names[s.student_id] ?? s.student_id}: {SKIP_REASON[s.reason] ?? s.reason}
              </li>
            ))}
          </ul>
        </div>
      ) : null}

      <div className="flex justify-end">
        <PrimaryButton type="button" onClick={onClose}>
          Selesai
        </PrimaryButton>
      </div>
    </div>
  );
}
