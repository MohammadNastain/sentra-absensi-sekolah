# Backup, Restore, dan Runbook Gangguan

Status: **draf Tahap 6.** Skrip sudah diuji di dev; nilai produksi (hosting, jadwal, lokasi penyimpanan) menunggu keputusan D-23 dan tinjauan hukum retensi D-26.

## 1. Strategi dua lapis

| Lapis | Tujuan | Alat |
|---|---|---|
| Cadangan logis harian | Memulihkan ke titik harian, memindahkan data, uji restore rutin | `infra/backup/backup.sh` (pg_dump format custom + SHA-256) |
| PITR (WAL archiving) | Memulihkan ke menit tertentu setelah kerusakan/penghapusan keliru | `archive_command`/pgBackRest/barman *(rencana, bergantung hosting)* |

Target awal (usulan, perlu persetujuan sekolah): **RPO ≤ 15 menit** (dengan PITR), **RTO ≤ 4 jam**. Tanpa PITR, RPO = 24 jam.

Yang dicadangkan:
- Database PostgreSQL (seluruh data tenant, audit, ekspor metadata).
- Berkas privat: `storage/app/private` (lampiran izin, ekspor, staging impor). Ekspor dan staging bersifat sementara, jadi prioritas rendah; **lampiran izin wajib**.
- Rahasia (`.env`, kunci aplikasi) dicadangkan terpisah di secret manager, **bukan** bersama dump.

## 2. Menjalankan

```
bash infra/backup/backup.sh backups            # dev; menulis backups/sentra-<stempel>.dump(+.sha256)
bash infra/backup/restore-test.sh backups/sentra-<stempel>.dump
```

`restore-test.sh` memulihkan ke database sementara `sentra_restore_test`, membandingkan jumlah baris tabel kunci dengan sumber, memeriksa jumlah tabel ber-RLS yang dipaksa dan jumlah trigger (pengaman append-only), lalu menghapus database sementara. Keluaran akhir `UJI RESTORE LULUS` atau `GAGAL`.

Hasil uji terakhir (dev, 19 Okt 2026): lulus. 9 tabel kunci sama persis, 24 tabel RLS dipaksa, 7 trigger.

Di produksi: set `PGHOST`, `PGUSER`, `PGPASSWORD`, `DB_NAME`; jalankan `backup.sh` dari penjadwal pada host terpisah dari server basis data; salin keluaran ke penyimpanan lain (lokasi berbeda, terenkripsi, retensi sesuai D-26). **Cadangan yang belum pernah dipulihkan dianggap belum ada**: jadwalkan `restore-test.sh` setidaknya bulanan pada lingkungan staging.

## 3. Restore sebenarnya (urutan)

1. Hentikan akses tulis: matikan `queue`, `scheduler`, dan `api` (atau pasang mode pemeliharaan).
2. Buat database kosong dan peran (`sentra_owner`, `sentra_app`) sesuai `infra/docker/postgres` / skrip penyiapan.
3. `pg_restore --no-owner --role sentra_owner --dbname <db> <berkas.dump>`; verifikasi checksum lebih dulu.
4. Jalankan `php artisan migrate --database=pgsql_owner --force` (menyamakan skema bila cadangan lebih lama dari kode).
5. Jalankan `php artisan attendance:close-day --backfill=7` agar hari yang terlewat dimaterialisasi (idempoten).
6. Pulihkan `storage/app/private` dari cadangan berkas; ekspor lama boleh dibuang (`exports:purge`).
7. Nyalakan kembali layanan, cek `/up`, login admin, dan halaman Kehadiran hari ini.
8. Catat insiden: waktu, penyebab, titik pemulihan, data yang hilang (bila ada), dan beri tahu sekolah.

## 4. Runbook gangguan

| Gejala | Periksa | Tindakan |
|---|---|---|
| Terminal tidak mencatat | Dashboard: perangkat offline; `/up` API; log `api` | Pulihkan jaringan/API. Selama itu petugas memakai mode bantuan atau kertas (lihat PANDUAN_PETUGAS). Tandai hari **gangguan** agar aturan alpa ditahan. |
| Banyak alpa sementara tidak wajar | Kesehatan hari (`perlu_pemeriksaan`), jumlah scan | Jangan verifikasi massal. Tandai gangguan; telusuri; lepaskan penahanan setelah data lengkap. |
| Ekspor tertahan "Mengantre" | Container `queue` hidup? Antrean `exports` ikut diproses? | Pastikan worker berjalan dengan `--queue=default,exports`; `docker compose restart queue`. |
| Login ditolak massal | Throttle login (5/menit/akun+IP), Redis | Tunggu 1 menit atau bersihkan cache Redis hanya pada dev. Di produksi periksa serangan brute force dari log. |
| Redis mati | Sesi dan antrean hilang | Pulihkan Redis; pengguna login ulang; job yang hilang (ekspor) diminta ulang. Data absensi aman di PostgreSQL. |
| Disk penuh | `storage/app/private`, WAL, log | Jalankan `exports:purge` dan `imports:purge`; rotasi log; tambah disk. |
| Hari terlewat penutupannya | `daily_attendance` kosong untuk tanggal itu | `php artisan attendance:close-day --date=YYYY-MM-DD` (penjadwal juga otomatis mengulang 3 hari terakhir). |

## 5. Yang masih terbuka (bergantung keputusan)

- Hosting dan lokasi data (D-23), sehingga PITR, enkripsi cadangan, dan lokasi salinan luar belum bisa dikunci.
- Retensi cadangan dan data (D-26) menunggu tinjauan hukum.
- Alert dan monitoring (uptime, disk, antrean, kegagalan cadangan) belum dipasang.
