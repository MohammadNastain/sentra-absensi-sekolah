# ADR 0005 — Kebijakan Kehadiran

- **Status:** Diterima (17 Okt 2026). Butir 1–4 diputuskan pada putaran pertama; butir 5 (mekanisme alpa) diputuskan pada putaran kedua.
- **Sumber:** keputusan pemilik produk 17 Okt 2026, PRD §4.5, §5, §8.3.
- **Diimplementasikan di:** Tahap 3 (butir 2–3), Tahap 5 (butir 1, 4, 5). Tidak ada kode untuk butir ini di Tahap 1.

## Diterima
1. **Belum tercatat ≠ Alpa.** Keduanya adalah nilai status yang berbeda di database, dashboard, laporan, dan ekspor.
2. **Pulang awal** hanya dicatat oleh petugas berwenang (mode bantuan/dashboard), dengan alasan wajib dan audit trail. Scan siswa sebelum jendela pulang dibuka selalu diperlakukan sebagai duplikat, tidak pernah sebagai check-out.
3. **Scan pulang tanpa catatan masuk** tidak menghasilkan kehadiran sah. Event disimpan dengan `result = needs_review` dan masuk antrean tinjauan petugas.
4. **Setiap koreksi** menyimpan pelaku, waktu, alasan, nilai sebelum, dan nilai sesudah (`attendance_adjustments` + `audit_logs`). Event mentah tidak pernah diubah.

## 5. Mekanisme Alpa (keputusan final, D-33)

### Hubungan dengan PRD — tidak ada aturan PRD yang diubah
PRD §8.3 menyatakan: *"Ketidakhadiran tidak boleh ditetapkan hanya berdasarkan kegagalan perangkat atau tidak adanya scan."*
PRD §5.2 mendefinisikan Alpa sebagai ketidakhadiran tanpa keterangan **setelah pemeriksaan dan aturan sekolah diterapkan**.

Keputusan ini **menjalankan** kedua aturan itu, bukan mengubahnya:
- Aturan sekolah setelah cutoff hanya menghasilkan status **sementara** `alpa_belum_diverifikasi`, bukan Alpa.
- Status **Alpa final** (`alpa`) hanya dapat terjadi setelah pemeriksaan manusia yang berwenang. Ini adalah "pemeriksaan" yang disyaratkan PRD §5.2.
- Bila perangkat atau layanan terganggu, aturan **ditahan**, sehingga ketidakhadiran tidak pernah ditetapkan berdasarkan kegagalan perangkat (§8.3).

### Status yang terlibat
| Status | Arti | Final? |
|---|---|---|
| `belum_tercatat` | Wajib hadir, belum ada catatan, cutoff belum lewat **atau** aturan ditahan | Tidak |
| `alpa_belum_diverifikasi` | Aturan sekolah terpenuhi setelah cutoff, hari dinilai sehat; menunggu verifikasi | **Tidak** — tidak dihitung sebagai alpa di laporan |
| `alpa` | Diverifikasi petugas berwenang | Ya |

Dipakai dua **nilai status berbeda**, bukan `alpa` + flag. Tujuannya agar query/laporan yang hanya memeriksa `status = 'alpa'` tidak pernah keliru menghitung status sementara sebagai alpa final. Database menegakkan aturan ini:
`CHECK (attendance_status <> 'alpa' OR (verified_by_user_id IS NOT NULL AND verified_at IS NOT NULL))`.

### Alur
1. Sekolah mengatur `absence_rule_enabled` dan `absence_cutoff_time` (waktu lokal; validasi: ≥ batas masuk + toleransi untuk semua jadwal aktif).
2. Setelah cutoff, job per sekolah lebih dulu menilai **kesehatan hari itu** (`attendance_day_health`):
   - `ok` — tidak ada indikasi gangguan.
   - `perlu_pemeriksaan` — salah satu pemicu berikut:
     - heartbeat terminal aktif hilang lebih dari ambang selama jendela masuk (default kumulatif 10 menit);
     - tingkat error server pada endpoint scan melebihi ambang;
     - tidak ada scan sama sekali padahal ada siswa wajib hadir;
     - admin/petugas menandai hari itu "gangguan".
3. Jika `ok`: siswa yang wajib hadir (aktif, terdaftar di kelas, ada jadwal, bukan libur), tanpa check-in sah, dan tanpa izin/sakit/dispensasi yang disetujui → `alpa_belum_diverifikasi`.
4. Jika `perlu_pemeriksaan`: **aturan ditahan**. Siswa tetap `belum_tercatat`, admin/petugas menerima peringatan beserta alasannya. Petugas berwenang dapat memeriksa lalu **melepas penahanan** (aturan dijalankan) atau membiarkannya, dengan alasan yang teraudit.
5. **Verifikasi/koreksi** oleh Wali Kelas (kelasnya), Petugas Piket, atau Admin:
   - `alpa_belum_diverifikasi` → `alpa` (verifikasi, bisa massal, dengan catatan); atau
   - → `izin`/`sakit`/`dispensasi`/`hadir`/`terlambat` (koreksi, dengan alasan wajib).
   Setiap perubahan tercatat di `attendance_adjustments` + `audit_logs` (pelaku, waktu, alasan, sebelum/sesudah).
6. Siswa yang scan masuk setelah cutoff (sebelum jendela pulang) → **Terlambat**. `alpa_belum_diverifikasi` tergantikan otomatis (teraudit). Bila status sudah `alpa` final, scan masuk antrean tinjauan dan tidak mengubah status secara otomatis.
7. Laporan menampilkan `alpa` dan `alpa_belum_diverifikasi` secara terpisah. Persentase/rekap alpa resmi **hanya** menghitung `alpa`.

### Konsekuensi
- Petugas perlu memverifikasi daftar harian. UI menyediakan verifikasi massal per kelas agar bebannya tetap ringan.
- Bila verifikasi tidak dilakukan, status tetap sementara dan terlihat di dashboard sebagai pekerjaan tertunda.
- Ambang kesehatan perangkat adalah pengaturan sekolah dengan nilai default yang perlu dikalibrasi saat pilot.

### Pengujian (Tahap 5)
| Kasus | Harapan |
|---|---|
| Hari sehat, siswa tanpa scan/izin, setelah cutoff | `alpa_belum_diverifikasi` |
| Sebelum cutoff | `belum_tercatat` |
| Terminal offline melewati ambang / admin menandai gangguan / nol scan | aturan ditahan, tetap `belum_tercatat`, peringatan dibuat |
| Izin disetujui / hari libur / tanpa jadwal / siswa nonaktif | tidak pernah alpa dalam bentuk apa pun |
| `INSERT/UPDATE` status `alpa` tanpa verifikator (langsung di DB) | ditolak constraint |
| Verifikasi tanpa izin / wali kelas di luar kelasnya | 403/404 |
| Koreksi tanpa alasan | 422 |
| Scan setelah cutoff | sementara → Terlambat; final → antrean tinjauan |
| Job dijalankan dua kali | hasil identik (idempoten) |
| Laporan | `alpa_belum_diverifikasi` tidak masuk hitungan alpa resmi |
