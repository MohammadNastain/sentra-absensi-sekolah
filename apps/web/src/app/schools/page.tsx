"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { LogoutButton } from "@/components/LogoutButton";
import { PendingInvitations } from "@/components/PendingInvitations";
import { api } from "@/lib/api";
import { roleLabel } from "@/lib/school-options";
import type { MySchool } from "@/lib/session";

/** Pemilih sekolah untuk user yang tergabung di lebih dari satu sekolah. */
export default function SchoolsPage() {
  const router = useRouter();
  const [schools, setSchools] = useState<MySchool[] | null>(null);

  useEffect(() => {
    let cancelled = false;
    api
      .GET("/me/schools")
      .then(({ data, response }) => {
        if (cancelled) return;
        if (response.status === 401) router.replace("/login");
        else setSchools(data?.data ?? []);
      })
      .catch(() => {
        if (!cancelled) router.replace("/login");
      });
    return () => {
      cancelled = true;
    };
  }, [router]);

  return (
    <main className="mx-auto w-full max-w-2xl flex-1 space-y-8 px-4 py-10">
      <header className="flex items-center justify-between">
        <h1 className="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-50">Pilih sekolah</h1>
        <LogoutButton />
      </header>

      <PendingInvitations />

      {schools === null ? (
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      ) : schools.length === 0 ? (
        <p className="text-zinc-600 dark:text-zinc-400">Anda belum tergabung di sekolah mana pun.</p>
      ) : (
        <ul className="space-y-3">
          {schools.map((entry) => {
            const usable = entry.membership_status === "active" && entry.school.accessible;
            return (
              <li key={entry.school.code}>
                {usable ? (
                  <Link
                    href={`/schools/${entry.school.code}`}
                    className="block rounded-2xl border border-zinc-200/70 bg-white shadow-card p-4 hover:border-brand-600 dark:border-zinc-800 dark:bg-zinc-900"
                  >
                    <p className="font-medium text-zinc-900 dark:text-zinc-50">{entry.school.name}</p>
                    <p className="text-sm text-zinc-600 dark:text-zinc-400">{entry.roles.map(roleLabel).join(", ")}</p>
                  </Link>
                ) : (
                  <div className="rounded-xl border border-zinc-200 bg-zinc-100 p-4 opacity-70 dark:border-zinc-800 dark:bg-zinc-900">
                    <p className="font-medium text-zinc-900 dark:text-zinc-50">{entry.school.name}</p>
                    <p className="text-sm text-zinc-600 dark:text-zinc-400">
                      {entry.membership_status === "suspended"
                        ? "Keanggotaan Anda ditangguhkan."
                        : "Sekolah ini sedang tidak aktif."}
                    </p>
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </main>
  );
}
