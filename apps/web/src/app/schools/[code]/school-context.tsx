"use client";

import type { Schemas } from "@sentra/api-client";
import { createContext, useContext } from "react";

export type SchoolProfile = Schemas["SchoolProfile"];
export type PermissionCode = Schemas["Permission"];

export interface SchoolContextValue {
  /** Kode sekolah dari URL (slug). */
  code: string;
  profile: SchoolProfile;
  /** ID user yang sedang login (null selagi dimuat). */
  meId: string | null;
  setProfile: (profile: SchoolProfile) => void;
  can: (permission: PermissionCode) => boolean;
}

const SchoolContext = createContext<SchoolContextValue | null>(null);

export const SchoolProvider = SchoolContext.Provider;

export function useSchool(): SchoolContextValue {
  const value = useContext(SchoolContext);
  if (!value) throw new Error("useSchool harus dipakai di dalam layout /schools/[code].");
  return value;
}
