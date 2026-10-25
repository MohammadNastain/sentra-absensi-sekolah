"use client";

import type { Schemas } from "@sentra/api-client";
import { createContext, useContext } from "react";

export type SchoolProfile = Schemas["SchoolProfile"];
export type PermissionCode = Schemas["Permission"];
export type MyEntitlements = Schemas["MyEntitlements"];

export interface SchoolContextValue {
  /** Kode sekolah dari URL (slug). */
  code: string;
  profile: SchoolProfile;
  /** ID user yang sedang login (null selagi dimuat). */
  meId: string | null;
  /** Nama user yang sedang login (null selagi dimuat). */
  meName: string | null;
  setProfile: (profile: SchoolProfile) => void;
  can: (permission: PermissionCode) => boolean;
  /** Nama paket berjalan; null bila tidak diketahui. */
  planName: string | null;
  /**
   * Apakah fitur termasuk hak sekolah (ADR 0009). Hanya UX: bila daftar fitur gagal dimuat dianggap ada, dan API tetap
   * menolak dengan 403 FEATURE_NOT_ENTITLED.
   */
  hasFeature: (feature: string) => boolean;
}

const SchoolContext = createContext<SchoolContextValue | null>(null);

export const SchoolProvider = SchoolContext.Provider;

export function useSchool(): SchoolContextValue {
  const value = useContext(SchoolContext);
  if (!value) throw new Error("useSchool harus dipakai di dalam layout /schools/[code].");
  return value;
}
