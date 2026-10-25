"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { AppShell } from "@/components/AppShell";
import { usePlatformNav } from "@/lib/platform-nav";
import { SchoolForm, type SchoolFormPayload } from "@/components/SchoolForm";
import { BackLink, PageHeader } from "@/components/ui";
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
    router.replace(school ? `/platform/${school.code}` : "/platform/schools");
  }

  return (
    <AppShell context="Platform" nav={nav} userLabel={me?.email} userName={me?.name}>
      <PageHeader title="Sekolah baru" description="Daftarkan sekolah baru ke platform." actions={<BackLink href="/platform/schools">Semua sekolah</BackLink>} />
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
