"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { IconShield } from "@/components/icons";
import { api } from "@/lib/api";

/** Spanduk selama Super Admin berada di sekolah lewat akses dukungan (D-21): pengingat bahwa semua tindakan tercatat. */
export function SupportBanner({ code, expiresAt }: { code: string; expiresAt: string }) {
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const until = new Date(expiresAt).toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });

  async function end() {
    setPending(true);
    try {
      await api.ensureCsrfCookie();
      await api.DELETE("/platform/schools/{school}/support-session", { params: { path: { school: code } } });
    } finally {
      router.replace(`/platform/${code}`);
    }
  }

  return (
    <div role="status" className="flex flex-wrap items-center gap-3 rounded-2xl border border-amber-300/70 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-100">
      <IconShield className="shrink-0" />
      <p className="min-w-0 flex-1">
        <strong>Mode dukungan Super Admin.</strong> Anda bertindak sebagai Admin Sekolah sampai pukul {until}. Semua yang Anda buka dan ubah tercatat di audit sekolah.
      </p>
      <button
        type="button"
        onClick={end}
        disabled={pending}
        className="rounded-xl bg-amber-900 px-3.5 py-1.5 text-sm font-semibold text-white transition hover:bg-amber-800 disabled:opacity-60 dark:bg-amber-200 dark:text-amber-950 dark:hover:bg-amber-100"
      >
        {pending ? "Mengakhiri…" : "Akhiri dukungan"}
      </button>
    </div>
  );
}
