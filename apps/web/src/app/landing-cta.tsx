"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { IconArrowRight } from "@/components/icons";
import { api } from "@/lib/api";
import { attempt } from "@/lib/errors";
import { destinationPath, resolveDestination } from "@/lib/session";

const PRIMARY =
  "inline-flex items-center justify-center gap-2 rounded-2xl bg-gradient-to-b from-brand-600 to-brand-800 px-5 py-3 text-sm font-bold text-white shadow-lift transition hover:brightness-110 focus-visible:outline-offset-2";
const COMPACT = "inline-flex items-center justify-center gap-2 rounded-xl bg-brand-700 px-4 py-2 text-sm font-bold text-white transition hover:bg-brand-800";

/** Tujuan tombol utama: pengunjung yang sudah masuk langsung ke dashboard-nya, yang lain ke halaman masuk. */
function useEntry(): { href: string; label: string } {
  const [entry, setEntry] = useState({ href: "/login", label: "Masuk" });

  useEffect(() => {
    let cancelled = false;
    resolveDestination()
      .then((destination) => {
        if (!cancelled && destination.kind !== "login") setEntry({ href: destinationPath(destination), label: "Buka dashboard" });
      })
      .catch(() => {
        // Gagal memeriksa sesi tidak menghalangi: tombol tetap mengarah ke halaman masuk.
      });
    return () => {
      cancelled = true;
    };
  }, []);

  return entry;
}

/** Tombol utama hero: masuk atau buka dashboard. */
export function HeroCta() {
  const { href, label } = useEntry();
  return (
    <Link href={href} className={PRIMARY}>
      {label} <IconArrowRight width={18} height={18} />
    </Link>
  );
}

/** Masuk satu klik ke sekolah demo (admin sekolah contoh). */
export function DemoCta({ onDark = false }: { onDark?: boolean }) {
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [failed, setFailed] = useState(false);

  async function start() {
    setPending(true);
    setFailed(false);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/auth/demo");
    });
    if (!outcome.ok) {
      setFailed(true);
      setPending(false);
      return;
    }
    router.replace(destinationPath(await resolveDestination()));
  }

  return (
    <span className="inline-flex flex-col items-center gap-1.5">
      <button
        type="button"
        onClick={start}
        disabled={pending}
        className={
          onDark
            ? "inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-5 py-3 text-sm font-bold text-brand-900 transition hover:bg-brand-50 disabled:opacity-70"
            : PRIMARY + " disabled:opacity-70"
        }
      >
        {pending ? "Menyiapkan demo…" : "Coba demo"} <IconArrowRight width={18} height={18} />
      </button>
      {failed ? (
        <span role="alert" className="text-xs font-medium text-red-600 dark:text-red-400">
          Demo belum tersedia. Coba lagi sebentar.
        </span>
      ) : null}
    </span>
  );
}

/** Tombol di bilah atas. */
export function NavCta() {
  const { href, label } = useEntry();
  return (
    <Link href={href} className={COMPACT}>
      {label}
    </Link>
  );
}
