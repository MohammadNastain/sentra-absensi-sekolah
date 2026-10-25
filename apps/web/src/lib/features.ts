/**
 * Pemetaan halaman sekolah ke fitur yang dibutuhkan (ADR 0009 §9). Hanya untuk UX: menyaring menu dan menampilkan
 * keadaan "tidak termasuk paket" bila halaman dibuka lewat URL. Penegakan sebenarnya ada di API (403 FEATURE_NOT_ENTITLED).
 *
 * Bentuk nilai: daftar "salah satu" kode fitur. Segmen dua tingkat (`teaching/subjects`) lebih spesifik daripada satu tingkat.
 */
const FEATURES_BY_PATH: Record<string, readonly string[]> = {
  attendance: ["attendance"],
  corrections: ["attendance.corrections"],
  leave: ["attendance.leave"],
  reports: ["attendance.reports"],
  schedules: ["attendance"],
  holidays: ["attendance"],
  calendar: ["attendance"],
  devices: ["attendance"],
  "academic-years": ["academic.years"],
  classes: ["academic.classes"],
  teachers: ["teachers"],
  teaching: ["academic.teaching_schedule"],
  "teaching/subjects": ["academic.subjects"],
  students: ["students"],
};

/** Fitur yang dibutuhkan halaman `/schools/{code}/...`; null untuk halaman inti (ringkasan, anggota, undangan, audit). */
export function requiredFeatures(pathname: string, code: string): readonly string[] | null {
  const prefix = `/schools/${code}/`;
  if (!pathname.startsWith(prefix)) return null;

  const [first, second] = pathname.slice(prefix.length).split("/");
  if (!first) return null;

  return FEATURES_BY_PATH[`${first}/${second}`] ?? FEATURES_BY_PATH[first] ?? null;
}
