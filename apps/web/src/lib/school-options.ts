import type { Schemas } from "@sentra/api-client";

export const EDUCATION_LEVELS = [
  "SD",
  "MI",
  "SMP",
  "MTs",
  "SMA",
  "SMK",
  "MA",
  "lainnya",
] as const satisfies readonly Schemas["EducationLevel"][];

export const TIMEZONES = [
  { value: "Asia/Jakarta", label: "WIB (Asia/Jakarta)" },
  { value: "Asia/Makassar", label: "WITA (Asia/Makassar)" },
  { value: "Asia/Jayapura", label: "WIT (Asia/Jayapura)" },
] as const satisfies readonly { value: Schemas["Timezone"]; label: string }[];

export const ROLE_LABELS: Record<Schemas["Role"], string> = {
  school_admin: "Admin Sekolah",
  principal: "Kepala Sekolah",
  homeroom_teacher: "Wali Kelas",
  duty_officer: "Petugas Piket",
  terminal_operator: "Operator Terminal",
  teacher: "Guru Mata Pelajaran",
  student: "Siswa",
};

export function roleLabel(role: string): string {
  return (ROLE_LABELS as Record<string, string>)[role] ?? role;
}
