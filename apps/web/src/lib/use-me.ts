"use client";

import { isProblem } from "@sentra/api-client";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { api } from "./api";
import type { Me } from "./session";

/** Mengarahkan error otorisasi API ke halaman yang benar; null bila bukan error otorisasi. */
export function redirectFor(error: unknown): string | null {
  if (!isProblem(error)) return null;
  switch (error.code) {
    case "UNAUTHENTICATED":
      return "/login";
    case "TWO_FACTOR_REQUIRED":
      // Beranda memilih antara setup 2FA (belum aktif) dan tantangan 2FA (sudah aktif, belum lulus).
      return "/";
    case "PLATFORM_ADMIN_REQUIRED":
      return "/";
    default:
      return null;
  }
}

/** Memuat profil user; tanpa sesi → /login. */
export function useMe(): Me | null {
  const router = useRouter();
  const [me, setMe] = useState<Me | null>(null);

  useEffect(() => {
    let cancelled = false;
    api
      .GET("/auth/me")
      .then(({ data }) => {
        if (cancelled) return;
        if (data) setMe(data.data);
        else router.replace("/login");
      })
      .catch(() => {
        if (!cancelled) router.replace("/login");
      });
    return () => {
      cancelled = true;
    };
  }, [router]);

  return me;
}
