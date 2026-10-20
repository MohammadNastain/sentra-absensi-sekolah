"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { AppShell } from "@/components/AppShell";
import { usePlatformNav } from "@/lib/platform-nav";
import { SchoolForm, type SchoolFormPayload } from "@/components/SchoolForm";
import { api } from "@/lib/api";
import { attempt, type FormFailure } from "@/lib/errors";
import { useMe } from "@/lib/use-me";

export default function NewSchoolPage() {
  const router = useRouter();
  const me = useMe();
  const nav = usePlatformNav();
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  async function create(payload: SchoolFormPayload) {
    setPending(true);
    setFailure(null);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/platform/schools", { body: payload });
    });
    setPending(false);

    if (!outcome.ok) {
      setFailure(outcome.failure);
      return;
    }
    const school = outcome.result.data?.data;
    router.replace(school ? `/platform/${school.code}` : "/platform");
  }

  return (
    <AppShell context="Platform" nav={nav} userLabel={me?.email} userName={me?.name}>
      <div>
        <Link href="/platform" className="text-sm text-emerald-700 hover:underline dark:text-emerald-400">
          ← Semua sekolah
        </Link>
        <h1 className="mt-2 text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-50">Sekolah baru</h1>
      </div>
      <div className="max-w-xl rounded-2xl border border-zinc-200/70 bg-white shadow-card p-6 dark:border-zinc-800 dark:bg-zinc-900">
        <SchoolForm
          mode="create"
          failure={failure}
          pending={pending}
          submitLabel="Buat sekolah"
          onSubmit={(payload) => {
            void create(payload);
          }}
        />
      </div>
    </AppShell>
  );
}
