/** Waktu ditampilkan di zona waktu sekolah (bukan zona browser), mis. "17 Okt 2026 22.35". */
export function formatDateTime(iso: string, timeZone: string): string {
  return new Date(iso).toLocaleString("id-ID", { timeZone, dateStyle: "medium", timeStyle: "short" });
}

/** Tanggal kalender polos ("2026-07-13") tanpa konversi zona waktu, mis. "13 Jul 2026". */
export function formatDate(date: string): string {
  return new Date(`${date}T00:00:00Z`).toLocaleDateString("id-ID", { timeZone: "UTC", dateStyle: "medium" });
}
