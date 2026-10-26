"use client";

import type { Schemas } from "@sentra/api-client";
import { useEffect, useState } from "react";
import { api } from "./api";

export type NamedRef = Schemas["LmsNamedRef"];
export type LmsPair = { class: NamedRef; subject: NamedRef };
/** Pilihan kelas-mapel: pairs null = semua kombinasi boleh (admin). */
export interface LmsOptions {
  pairs: LmsPair[] | null;
  classes: NamedRef[];
  subjects: NamedRef[];
}

/** Ukuran berkas yang mudah dibaca ("1,2 MB"). */
export function formatBytes(size: number): string {
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(0)} KB`;
  return `${(size / 1024 / 1024).toLocaleString("id-ID", { maximumFractionDigits: 1 })} MB`;
}

/** Bagi tanggal-waktu lokal browser ("2026-11-02T08:00") untuk <input type="datetime-local"> dari ISO server. */
export function toLocalInput(iso: string | null, timeZone: string): string {
  if (!iso) return "";
  // sv-SE menghasilkan "2026-11-02 08:00:00" di zona yang diminta; ambil sampai menit.
  return new Date(iso).toLocaleString("sv-SE", { timeZone }).slice(0, 16).replace(" ", "T");
}

/**
 * Mengubah isian datetime-local (waktu dinding di zona sekolah) menjadi ISO UTC. Zona sekolah tetap (WIB/WITA/WIT, tanpa DST),
 * jadi selisihnya dihitung dari zona itu sendiri pada tanggal yang sama.
 */
export function fromLocalInput(local: string, timeZone: string): string | null {
  if (!local) return null;
  const asUtc = new Date(`${local}:00Z`);
  const shown = new Date(asUtc.toLocaleString("sv-SE", { timeZone }).replace(" ", "T") + "Z");
  const offset = shown.getTime() - asUtc.getTime();
  return new Date(asUtc.getTime() - offset).toISOString();
}

/** Pilihan kelas-mapel yang boleh dikelola pengguna (guru: dari jadwal mengajar; admin: semua). null selagi dimuat. */
export function useLmsOptions(code: string): { options: LmsOptions | null; error: string | null } {
  const [options, setOptions] = useState<LmsOptions | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    api
      .GET("/schools/{school}/lms/options", { params: { path: { school: code } } })
      .then(({ data, error: problem }) => {
        if (cancelled) return;
        if (data) setOptions(data.data);
        else if (problem !== undefined) setError("Pilihan kelas dan mata pelajaran tidak dapat dimuat.");
      })
      .catch(() => {
        if (!cancelled) setError("Pilihan kelas dan mata pelajaran tidak dapat dimuat.");
      });
    return () => {
      cancelled = true;
    };
  }, [code]);

  return { options, error };
}

/** Mapel yang dapat dipilih untuk sebuah kelas: bagi guru dari pasangan jadwalnya, bagi admin semua mapel. */
export function subjectsFor(options: LmsOptions, classId: string): NamedRef[] {
  if (options.pairs === null) return options.subjects;
  return options.pairs.filter((p) => p.class.id === classId).map((p) => p.subject);
}
