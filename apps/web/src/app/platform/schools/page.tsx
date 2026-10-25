"use client";

import type { Schemas } from "@sentra/api-client";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { AppShell } from "@/components/AppShell";
import { hasRememberedSchool, rememberSchool, usePlatformNav } from "@/lib/platform-nav";
import { FormAlert, StatusBadge } from "@/components/ui";
import { api } from "@/lib/api";
import { toFormFailure } from "@/lib/errors";
import { redirectFor, useMe } from "@/lib/use-me";

type School = Schemas["School"];

/** Daftar seluruh sekolah (Super Admin). */
export default function PlatformPage() {
  const router = useRouter();
  const me = useMe();
  const nav = usePlatformNav();
  const [schools, setSchools] = useState<School[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    api
      .GET("/platform/schools")
      .then(({ data, error: problem }) => {
        if (cancelled) return;
        const target = redirectFor(problem);
        if (target) router.replace(target);
        else if (problem !== undefined) setError(toFormFailure(problem).message);
        else {
          const list = data?.data ?? [];
          setSchools(list);
          // Menu sekolah di sidebar langsung tersedia sejak login: pilih sekolah aktif pertama bila belum ada pilihan.
          const first = list.find((school) => school.status === "active") ?? list[0];
          if (first && !hasRememberedSchool()) rememberSchool(first.code);
        }
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
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-50">Sekolah</h1>
        <Link
          href="/platform/new"
          className="rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800"
        >
          Sekolah baru
        </Link>
      </div>

      {error ? <FormAlert>{error}</FormAlert> : null}

      {schools === null && !error ? (
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      ) : null}

      {schools && schools.length === 0 ? (
        <p className="text-zinc-600 dark:text-zinc-400">Belum ada sekolah. Buat sekolah pertama.</p>
      ) : null}

      {schools && schools.length > 0 ? (
        <div className="overflow-x-auto rounded-2xl border border-zinc-200/70 bg-white shadow-card dark:border-zinc-800 dark:bg-zinc-900">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-zinc-200 bg-zinc-50/70 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950/40 dark:text-zinc-400">
              <tr>
                <th scope="col" className="px-4 py-3 font-semibold">Nama</th>
                <th scope="col" className="px-4 py-3 font-semibold">Kode</th>
                <th scope="col" className="px-4 py-3 font-semibold">Jenjang</th>
                <th scope="col" className="px-4 py-3 font-semibold">Status</th>
                <th scope="col" className="px-4 py-3 text-right font-semibold">Aksi</th>
              </tr>
            </thead>
            <tbody>
              {schools.map((school) => (
                <tr key={school.id} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                  <td className="px-4 py-3">
                    <Link
                      href={`/platform/${school.code}`}
                      className="font-semibold text-zinc-900 underline-offset-4 transition hover:text-brand-700 hover:underline dark:text-zinc-100 dark:hover:text-brand-300"
                    >
                      {school.name}
                    </Link>
                  </td>
                  <td className="px-4 py-3 font-mono text-zinc-700 dark:text-zinc-300">{school.code}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{school.education_levels.join(", ")}</td>
                  <td className="px-4 py-3">
                    <StatusBadge status={school.status} />
                  </td>
                  <td className="px-4 py-3 text-right">
                    <Link
                      href={`/schools/${school.code}`}
                      className="inline-flex items-center rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-700"
                    >
                      Buka sekolah
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </AppShell>
  );
}
