# Monitoring dan alert

Status: **draf Tahap 6.** Alat pemantau dan kanal alert menunggu keputusan hosting (D-23); dokumen ini menetapkan sinyal, ambang awal, dan tindakan, supaya bisa dipasang di alat apa pun.

## Sinyal yang dipantau

| Sinyal | Sumber | Ambang awal | Tindakan |
|---|---|---|---|
| API hidup | `GET /up` (Laravel), probe tiap 1 menit | 2 kegagalan berturut-turut | Cek kontainer `api`, basis data, log |
| Web hidup | `GET /login` mengembalikan 200 | 2 kegagalan berturut-turut | Cek proses Next (`next start`) |
| Tingkat error scan | Kunci cache `scan-outcomes:{sekolah}:{tanggal}:errors` / `:total` (middleware `CountScanOutcomes`) | >= 5% dengan minimal 20 request (`HEALTH_ERROR_RATE`, `HEALTH_ERROR_MIN_REQUESTS`) | Periksa log API; hari itu otomatis berstatus `perlu_pemeriksaan` |
| Terminal hilang | Tabel `device_presence` (satu baris per menit per terminal) dan `devices.last_seen_at` | > 10 menit kumulatif di jendela masuk (`HEALTH_OFFLINE_MINUTES`); dashboard menandai offline > 2 menit | Hubungi petugas sekolah; cek jaringan terminal |
| Penjadwal berjalan | Perintah `attendance:close-day` (tiap jam), `exports:purge`, `imports:purge`, `audit:scrub-ip` | Tidak ada eksekusi > 2 jam (close-day) atau > 26 jam (harian) | Cek `php artisan schedule:work` / cron |
| Antrean | Worker `queue` (`--queue=default,exports`), tabel `jobs` / `failed_jobs` | `failed_jobs` > 0, atau usia job tertua > 10 menit | `php artisan queue:retry`, cek log |
| Cadangan | `infra/backup/backup.sh` | Tidak ada cadangan baru dalam 26 jam; uji restore bulanan gagal | Lihat `BACKUP_RESTORE.md` |
| Disk | Volume basis data dan penyimpanan privat | > 80% terpakai | Perluas volume, tinjau retensi |
| Hari bermasalah | Tabel `attendance_day_health` berstatus `perlu_pemeriksaan` | Setiap kejadian, kirim ke admin/petugas sekolah | Petugas memeriksa lalu melepas atau membiarkan penahanan |

## Alert dan penerima

- Alert infrastruktur (API/web mati, antrean, penjadwal, cadangan, disk) ke pengelola teknis.
- Alert hari bermasalah sudah tampil di panel absensi sekolah (alasan `NO_SCANS`, `DEVICE_OFFLINE`, `SCAN_ERROR_RATE`, `MANUAL`).
- Kanal (surel/pesan) dan jam siaga ditetapkan setelah D-23.

## Belum ada

- Pengiriman alert otomatis dan dasbor metrik (menunggu D-23).
- Ambang bersifat konfigurasi server, bukan per sekolah; kalibrasi saat uji lapangan pilot.
