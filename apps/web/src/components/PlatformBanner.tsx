import Link from "next/link";
import { IconShield } from "@/components/icons";

/** Pengingat bagi Super Admin yang sedang berada di dalam sekolah (D-21): akses penuh, semua tindakan tercatat. */
export function PlatformBanner({ code }: { code: string }) {
  return (
    <div role="status" className="flex flex-wrap items-center gap-3 rounded-2xl border border-amber-300/70 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-100">
      <IconShield className="shrink-0" />
      <p className="min-w-0 flex-1">
        <strong>Anda masuk sebagai Super Admin.</strong> Akses penuh seperti Admin Sekolah; yang Anda buka dan ubah tercatat di audit sekolah ini.
      </p>
      <Link
        href={`/platform/${code}`}
        className="rounded-xl bg-amber-900 px-3.5 py-1.5 text-sm font-semibold text-white transition hover:bg-amber-800 dark:bg-amber-200 dark:text-amber-950 dark:hover:bg-amber-100"
      >
        Kembali ke Platform
      </Link>
    </div>
  );
}
