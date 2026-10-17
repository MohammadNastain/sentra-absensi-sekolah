"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { destinationPath, resolveDestination } from "@/lib/session";

/** Beranda hanya memilih tujuan: login, 2FA, platform, atau sekolah. */
export default function Home() {
  const router = useRouter();

  useEffect(() => {
    let cancelled = false;
    resolveDestination()
      .then((destination) => {
        if (!cancelled) router.replace(destinationPath(destination));
      })
      .catch(() => {
        if (!cancelled) router.replace("/login");
      });
    return () => {
      cancelled = true;
    };
  }, [router]);

  return (
    <main className="flex flex-1 items-center justify-center text-zinc-600 dark:text-zinc-400">
      <p role="status">Memuat…</p>
    </main>
  );
}
