/** Waktu ditampilkan di zona waktu sekolah (bukan zona browser), mis. "17 Okt 2026 22.35". */
export function formatDateTime(iso: string, timeZone: string): string {
  return new Date(iso).toLocaleString("id-ID", { timeZone, dateStyle: "medium", timeStyle: "short" });
}
