import type { Schemas } from "@sentra/api-client";
import { api } from "./api";

export type Me = Schemas["Me"];
export type MySchool = Schemas["MySchool"];

export type Destination =
  | { kind: "login" }
  | { kind: "two-factor" }
  | { kind: "platform" }
  | { kind: "school"; code: string }
  | { kind: "schools" }
  | { kind: "no-access" };

export function destinationPath(destination: Destination): string {
  switch (destination.kind) {
    case "login":
      return "/login";
    case "two-factor":
      return "/two-factor";
    case "platform":
      return "/platform";
    case "school":
      return `/schools/${destination.code}`;
    case "schools":
      return "/schools";
    case "no-access":
      return "/no-access";
  }
}

/**
 * Menentukan halaman tujuan sesudah sesi terbentuk. Super Admin wajib lulus 2FA sebelum ke /platform
 * (API menolak 403 TWO_FACTOR_REQUIRED bila belum); user biasa diarahkan menurut sekolah yang dapat diakses.
 */
export async function resolveDestination(): Promise<Destination> {
  const me = await api.GET("/auth/me");
  if (me.response.status === 401 || !me.data) return { kind: "login" };

  if (me.data.data.is_platform_admin) {
    return me.data.data.two_factor_passed ? { kind: "platform" } : { kind: "two-factor" };
  }

  const schools = await api.GET("/me/schools");
  const accessible = (schools.data?.data ?? []).filter(
    (s) => s.membership_status === "active" && s.school.accessible,
  );
  if (accessible.length === 1 && accessible[0]) return { kind: "school", code: accessible[0].school.code };
  if (accessible.length > 1) return { kind: "schools" };
  return { kind: "no-access" };
}
