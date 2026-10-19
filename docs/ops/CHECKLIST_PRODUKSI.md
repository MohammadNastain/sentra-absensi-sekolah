# Checklist Produksi (Tahap 6)

Centang satu per satu sebelum pilot. Bagian yang bergantung keputusan hosting (D-23) ditandai ⏳.

## Konfigurasi aplikasi (`apps/api/.env`)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `APP_KEY` unik, disimpan di secret manager ⏳
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`, `SESSION_DOMAIN` sesuai domain dashboard
- [ ] `SESSION_ENCRYPT=true`
- [ ] `FRONTEND_URL` (CORS dan domain stateful Sanctum) hanya domain dashboard produksi
- [ ] `LOGIN_ATTEMPTS_PER_MINUTE` tetap default (5) di produksi
- [ ] Kredensial DB: peran `sentra_app` (tanpa hak DDL) untuk aplikasi; `sentra_owner` hanya untuk migrasi
- [ ] Surel: transport nyata (bukan Mailpit); SPF/DKIM domain pengirim ⏳
- [ ] `QUEUE_CONNECTION=redis`; worker `--queue=default,exports`; `exports:purge`, `imports:purge`, `attendance:close-day` terjadwal (scheduler berjalan)

## Jaringan dan transport

- [ ] HTTPS di dashboard, API, dan terminal; HSTS aktif (sudah dikirim aplikasi di produksi) ⏳
- [ ] API tidak terbuka langsung ke publik selain lewat rute yang dibutuhkan; PostgreSQL dan Redis tidak terpapar publik
- [ ] Header keamanan terverifikasi dari luar (CSP, X-Frame-Options, nosniff, Permissions-Policy; kamera hanya `/terminal`)
- [ ] Terminal: perangkat dipasangkan, token tidak dibagikan, jumlah perangkat sesuai batas sekolah

## Data dan cadangan

- [ ] Cadangan harian berjalan dan salinan di lokasi terpisah ⏳
- [ ] **Uji restore berhasil** pada staging dan dicatat tanggalnya (lihat `BACKUP_RESTORE.md`)
- [ ] PITR aktif ⏳
- [ ] Retensi sesuai D-26 setelah tinjauan hukum: IP audit 90 hari, foto 30 hari setelah keluar, ekspor 7 hari (sudah), staging impor 30 hari (sudah) ⏳

## Keamanan

- [ ] `composer audit` dan `pnpm audit --prod` bersih (19 Okt 2026: bersih)
- [ ] DAST (OWASP ZAP baseline) terhadap staging; temuan menengah ke atas diperbaiki
- [ ] Pentest eksternal dan perbaikan temuan ⏳
- [ ] Akun admin: autentikasi dua langkah aktif; tidak ada akun demo (`DemoSeeder` tidak dijalankan di produksi)
- [ ] Audit log dapat dibaca admin; tidak ada jalur menghapus audit

## Operasional

- [ ] Monitoring dan alert: sinyal, ambang, dan tindakan sudah ditetapkan di `MONITORING.md`; pemasangan alat dan kanal alert menunggu D-23 ⏳
- [ ] Runbook gangguan dibaca petugas (`BACKUP_RESTORE.md` §4, `PANDUAN_PETUGAS.md`)
- [ ] Prosedur manual saat gangguan disetujui sekolah pilot
- [ ] Persetujuan pemilik produk atas angka golden dataset laporan (`ReportGoldenDatasetTest`)
- [ ] Uji beban 30 menit (k6) dengan jumlah siswa sebenarnya; hasil dicatat
- [ ] Uji perangkat RFID nyata sesuai `docs/hardware/PROFIL_RFID.md`
