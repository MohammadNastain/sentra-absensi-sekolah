# IMPLEMENTATION PLAN — Sentra Absensi Sekolah

**Status:** Draf tahap 1 — menunggu persetujuan
Urutan di bawah adalah **urutan dependensi, bukan janji durasi** [PRD 14]. Estimasi waktu dibuat setelah jumlah developer, perangkat keras, dan standar rilis dipastikan.

Pemetaan ke PRD: Tahap 1 ≈ Sprint 0 · Tahap 2 ≈ Sprint 1 · Tahap 3 ≈ Sprint 2 · Tahap 4 ≈ Sprint 3 · Tahap 5 ≈ Sprint 4 · Tahap 6 ≈ Sprint 5 · Tahap 7 = pasca-MVP.
Kode acceptance criteria (A1, C6, dst.) merujuk PRD §12 dengan nomor urut checklist per kelompok.

```text
Tahap 0 ─▶ Tahap 1 ─▶ Tahap 2 ─▶ Tahap 3 ─▶ Tahap 4 ─▶ Tahap 5 ─▶ Tahap 6 ─▶ (Pilot) ─▶ Tahap 7*
 Keputusan  Fondasi    Data       QR &       RFID &     Rekap,     Hardening
 & env      auth/      sekolah    terminal   bantuan    koreksi,   & pilot
            tenant/                                      laporan
            audit
                                    ▲ D-19 perangkat       ▲ D-18 hardware RFID harus tiba sebelum Tahap 4
* Tahap 7 (wajah) hanya setelah gerbang kepatuhan di DECISIONS.md §5
```

Aturan umum untuk setiap tahap:
- Selesai = semua test hijau di CI, tanpa test yang di-skip atau dinonaktifkan, migrasi dapat diulang dari nol, dan dokumentasi diperbarui.
- Tidak ada mock/placeholder yang tampil sebagai fitur produksi. Fitur yang belum ada tidak muncul di UI.
- Review (oleh Anda) di akhir setiap tahap sebelum tahap berikutnya dimulai.

---

## Tahap 0 — Keputusan & Lingkungan

**Dependensi:** persetujuan dokumen tahap 1.
**Pekerjaan:**
- Putuskan D-01, D-02, D-05 (blok Tahap 1); jadwalkan keputusan D-18/D-19.
- Pasang toolchain (rekomendasi: Docker Desktop + WSL2; atau PHP 8.4 + Composer + PostgreSQL + Redis native). XAMPP tidak disentuh.
- `git init`, `.gitignore`, `.editorconfig`, konvensi commit, branch utama terlindungi (bila memakai GitHub).
- Kerangka monorepo (pnpm workspaces), `infra/docker/compose.yaml` (pgsql, redis, minio, mailpit).
- CI kerangka: lint + test kosong yang benar-benar berjalan (bukan placeholder lulus palsu).

**Deliverable:** repo bersih yang bisa di-clone dan dijalankan `docker compose up`; ADR untuk keputusan yang diambil.
**Acceptance:** developer baru dapat menjalankan seluruh stack lokal dari README dalam satu sesi; CI berjalan pada setiap push.

**Status (17 Okt 2026): terverifikasi di lokal, belum di CI.**
- Sudah dibuat: git, monorepo, `compose.yaml`, image PHP dev, init role/database PostgreSQL, `scripts/init-env.mjs`, `scripts/verify-dev-env.sh`, CI (`.github/workflows/ci.yml`), ADR 0001–0008.
- `verify-dev-env.sh` lulus 100% di mesin dev (Docker Desktop 29.8.2). Temuan saat verifikasi: image resmi PostgreSQL memakai `trust` untuk koneksi loopback, sehingga password salah diterima. Diperbaiki dengan `--auth-host=scram-sha-256` di `compose.yaml`.
- **Belum:** repo belum memiliki remote GitHub, sehingga CI belum pernah berjalan.

---

## Tahap 1 — Fondasi: Auth, Tenant, RBAC, Audit

> Spesifikasi rinci (model identitas–keanggotaan, API, pipeline middleware, 40+ kasus uji, acceptance) ada di [`TAHAP_1_FONDASI.md`](TAHAP_1_FONDASI.md). Spesifikasi itu **menggantikan** butir yang berbeda di bawah (mis. `users`/`user_roles` → `users` + `school_memberships` + `membership_roles`).

**Dependensi:** Tahap 0 terverifikasi; keputusan D-34, D-37 (lihat DECISIONS).
**Pekerjaan:**
- Laravel API: struktur `Domain/*`, `Clock` yang dapat diinjeksi, error problem+json, request ID, log terstruktur.
- Migrasi: `schools`, `school_settings`, `users`, `roles`, `user_roles`, `audit_logs`, tabel framework. Role DB `sentra_owner`/`sentra_app`, RLS + trigger append-only.
- Tenant context middleware + trait `BelongsToSchool` + penyetel `app.school_id`.
- Sanctum SPA: login/logout/me, rate limit, penolakan user nonaktif, ganti password. TOTP untuk Super Admin.
- Policy & enum izin; matriks role (ARCHITECTURE §7).
- `AuditLogger` dengan allowlist redaksi.
- Endpoint platform: CRUD sekolah + buat Admin Sekolah pertama.
- Next.js dashboard: layout, login, guard sesi, halaman platform sekolah (Super Admin), Bahasa Indonesia.
- OpenAPI generator + paket `api-client`.
- Harness uji: factory dua sekolah, helper `actingAsRole`, matriks otorisasi otomatis (lihat TESTING_STRATEGY §4).

**Deliverable:** login multi-role berjalan; Super Admin dapat membuat sekolah dan admin; audit tercatat.
**Test plan:** unit (policy, redaksi audit), feature (auth, rate limit, user nonaktif), database (RLS, append-only, constraint `users`), tenancy (akses silang → 404), migrasi fresh/rollback.
**Acceptance:** A1, A2, A4 (untuk endpoint yang ada), A5 (aksi admin), PRD Sprint 0 DoD: migrasi dapat diulang dan uji isolasi tenant dasar lulus.

**Progres (17 Okt 2026):**
- [x] Scaffold `apps/api` (Laravel 13.34, Fortify, Sanctum, predis, Pest, Larastan level 8, Pint); koneksi `pgsql` (sentra_app) & `pgsql_owner` (migrasi).
- [x] Migrasi: fungsi konteks RLS, `users`, `platform_admins`, `schools`, `roles`, `school_memberships`, `membership_roles`, `membership_invitations` (+ lookup token `SECURITY DEFINER`), `audit_logs` (append-only). Fresh → rollback → migrate lulus.
- [x] `DatabaseContext` (set/reset `app.school_id`, `app.user_id`, `app.platform`).
- [x] Test DB (32 lulus, sebagai sentra_app): T10, U02, U04, I05, FK komposit, REVOKE, constraint.
- [x] Job CI `api` (belum pernah berjalan, karena belum ada remote).
- [x] Model, enum `Role`/`Permission`, `TenantContext`, problem+json (RFC 9457) + request ID.
- [x] Pipeline middleware: `ResetDatabaseContext` (global), `EnsureUserActive` (+ sesi absolut 12 jam), `ResolveSchoolMembership`, `RequireSchoolPermission`, `EnsurePlatformAdmin` (+2FA).
- [x] Auth Fortify: login, logout, 2FA, lupa/reset/ganti password, rate limit; audit event auth; `AuditLogger` + redaksi allowlist.
- [x] Endpoint: `auth/me`, `me/schools`, `schools/{school}` (profil), `platform/schools` (daftar).
- [x] Test (67 lulus): A01–A06, A08, T01–T04, T08 (meta), T09 (matriks), T11, T13, + test DB sebelumnya.
- [x] Endpoint platform (buat/ubah/suspend/aktifkan sekolah, undang admin pertama), undangan & anggota (I01–I05, R01–R03), audit-log API, `TenantAwareJob`, DemoSeeder.
- [x] Test API lengkap (270 lulus): ditambah U01, U03, A07, T05–T07, T12, M03 (snapshot skema `database/schema-snapshot.txt`), M04, matriks otorisasi untuk seluruh rute. Pint + Larastan level 8 bersih; CI hijau di PR #1.
- [x] OpenAPI 3.1 (`apps/api/openapi.yaml`, ditulis tangan; test drift `OpenApiContractTest` menjamin setiap rute terdokumentasi dan sebaliknya) + paket `packages/api-client` (tipe dari `openapi-typescript` 7.13.0, klien `openapi-fetch` 0.17.0 dengan cookie + CSRF). Job CI `web` membangkitkan ulang skema dan menggagalkan build bila `schema.d.ts` tidak sinkron.
- [x] Frontend (irisan 1): proxy `/api` + `/sanctum` ke Laravel (cookie same-origin), halaman login, tantangan 2FA, lupa password, reset password; teruji manual end-to-end terhadap API sungguhan. Service `api` di compose diganti ke server bawaan PHP (`artisan serve` tidak meneruskan `DB_HOST` ke worker).
- [x] Frontend (irisan 2): halaman terima undangan (`/invitations/[token]`, akun baru atau akun lama), pemilih sekolah + undangan tertunda (`/schools`), `/no-access`, logout, `?next=` aman di login. Service `queue` ditambahkan ke compose (tanpa worker, email undangan tidak pernah terkirim di dev). Teruji nyata: undang → Mailpit → buat akun → login.
- [x] Frontend (irisan 3): area Super Admin (`/platform`: daftar, buat, detail, ubah profil, tangguhkan/aktifkan, undang Admin Sekolah pertama) dan kerangka sekolah (`/schools/[code]`: layout dengan izin, ringkasan, ubah profil). `DemoSeeder` menambah `superadmin@demo.test` dengan 2FA aktif (kunci TOTP tetap `JBSWY3DPEHPK3PXP`, hanya dev). Teruji nyata dengan kode TOTP sungguhan.
- [x] Frontend (irisan 4): halaman sekolah Anggota (ubah peran, tangguhkan, aktifkan, akhiri, dengan alasan), Undangan (buat, kirim ulang, cabut, status pengiriman), dan Audit (filter, rincian sebelum/sesudah, paginasi); navigasi menurut izin; parameter `page` masuk kontrak OpenAPI.
- [x] Frontend (irisan 5): setup 2FA (`/account/two-factor`): konfirmasi password, QR + kunci manual, konfirmasi TOTP, kode pemulihan, matikan 2FA; Super Admin tanpa 2FA diarahkan ke sana; tautan "Keamanan" di header. Teruji nyata dengan TOTP sungguhan (aktivasi langsung membuka `/platform`, tanpa login ulang).
- [x] E2E Playwright (`apps/web/e2e/tahap1.spec.ts`, `pnpm e2e`): Super Admin login + 2FA → buat sekolah → undang admin → admin menerima undangan lewat Mailpit → login → undang Wali Kelas → Wali Kelas hanya melihat menu sesuai izin, ditolak API di halaman audit, dan tidak bisa membuka sekolah lain → admin melihat jejak audit. Lulus di stack lokal (Chrome terpasang).
- [ ] E2E di CI (butuh stack penuh + seed + Chromium) dan penutupan Tahap 1.

---

## Tahap 2 — Data Sekolah

**Dependensi:** Tahap 1.
**Pekerjaan:**
- Pengaturan & profil sekolah (zona waktu, jenjang).
- CRUD tahun ajaran, guru, kelas (rombel), penugasan wali kelas.
- CRUD siswa (cari trigram, filter, nonaktifkan, status), enrollment & pindah kelas, foto (upload privat + signed URL).
- Impor siswa CSV/XLSX: upload → validasi async → preview baris gagal → commit baris valid (D-15); templat unduhan.
- Manajemen pengguna & role; reset password oleh admin.
- Jadwal berversi (scope sekolah/jenjang/kelas) + hari libur + tampilan kalender; `ScheduleResolver`.
- Scope wali kelas berlaku di daftar siswa/kelas.

**Deliverable:** admin dapat menyiapkan sekolah lengkap tanpa input satu per satu [PRD Sprint 1 DoD].
**Test plan:** unit `ScheduleResolver` (prioritas scope, tanggal efektif, libur, hari tanpa jadwal), validator baris impor; database (exclusion enrollment/jadwal/tahun ajaran, unique NIS); integrasi impor dengan fixture (valid, campuran, encoding, kolom hilang, duplikat dalam file vs DB, file >batas, tipe palsu, XLSX rusak); otorisasi wali kelas; E2E: setup sekolah → impor → lihat siswa.
**Acceptance:** B1–B4, A3, G4 (validasi file impor), D3 (libur tidak menghasilkan sesi).

---

## Tahap 3 — QR & Terminal

**Dependensi:** Tahap 2; keputusan D-19 (perangkat & cara baca QR), D-09, D-10.
**Pekerjaan:**
- Penerbitan/terbit ulang/blokir QR; hashing HMAC berversi; job PDF kartu QR per kelas.
- Device: registrasi, kode pairing, guard `device`, heartbeat, pencabutan.
- `RecordScanAction`: idempotensi, klasifikasi input, lookup kredensial, resolusi jadwal, lock harian, mesin keadaan, event + proyeksi harian, audit.
- App terminal: pairing, penangkapan input scanner (level dokumen), 9 state UI, penanganan timeout/koneksi, daftar transaksi terbaru, CSP ketat.
- Dashboard: ringkasan harian (sudah hadir/terlambat/belum tercatat), transaksi terbaru (polling), peringatan device offline.

**Deliverable:** alur QR end-to-end yang dapat diaudit pada perangkat terminal yang disepakati [PRD Sprint 2 DoD].
**Test plan:** unit tabel batas waktu mesin keadaan (setiap baris tabel ARCHITECTURE §8.1 ±1 menit, zona waktu WIB/WITA/WIT), token QR (format, entropi, tanpa PII); feature: semua `reason_code`, replay idempotensi identik, device dicabut; **uji konkurensi** (N scan paralel → tepat 1 accepted); E2E terminal dengan input keyboard berkecepatan scanner + simulasi jaringan putus; uji log tidak memuat token.
**Acceptance:** C1, C2, C4 (QR), C5, C6, D1, D2, D5, G1, G2 (endpoint terminal).

---

## Tahap 4 — RFID, Mode Bantuan, Operasi Harian

**Dependensi:** Tahap 3; **hardware RFID sudah tersedia** (D-18). Mode bantuan bisa dikerjakan paralel bila hardware terlambat.
**Pekerjaan:**
- Profil normalisasi UID untuk reader terpilih (terdokumentasi di `docs/hardware/`).
- Registrasi kartu RFID (tap di reader pada PC admin), pemetaan, blokir, ganti; deteksi kartu sudah terpakai.
- Mode bantuan: sesi petugas di terminal & dashboard, pencarian minimal, verifikasi visual + alasan, pencatatan pulang awal oleh petugas (D-09), laporan anomali.
- Antrean review `needs_review` dan resolusinya (menghasilkan adjustment).
- Penanganan kesalahan perangkat, indikator koneksi.
- Uji beban jam masuk (k6).

**Deliverable:** reader yang ditetapkan lulus uji fisik pada perangkat target [PRD Sprint 3 DoD]; alur kartu tertinggal nonbiometrik.
**Test plan:** unit normalizer UID (vektor uji dari pembacaan kartu nyata); feature: sesi bantuan (tanpa izin/kedaluwarsa/sekolah lain ditolak), CHECK constraint assisted; protokol uji hardware (kecepatan, tap ganda, kartu rusak, layout keyboard, 50+ kartu berurutan); load test p95 ≤ 2 detik.
**Acceptance:** C3, C4 (RFID), E1, E2, E4, E5; E3 dipenuhi dengan fitur wajah **tidak tersedia** sama sekali.

---

## Tahap 5 — Rekap, Izin, Koreksi, Laporan

**Dependensi:** Tahap 3 (event), Tahap 4 (review queue). Keputusan D-12, D-13, D-14.
**Pekerjaan:**
- `DailyAttendanceCalculator` final + job penutupan hari per sekolah (materialisasi `belum_tercatat`, idempoten, dapat dijalankan ulang).
- Finalisasi alpa manual (massal dengan alasan).
- Izin/sakit/dispensasi: pengajuan, persetujuan, lampiran privat, perhitungan ulang.
- Koreksi: pengajuan wali kelas → persetujuan; koreksi langsung oleh admin; histori.
- Laporan: harian, bulanan, per kelas/jenjang, per siswa, keterlambatan, rekap status, riwayat scan, koreksi, ditolak/duplikat.
- Ekspor CSV/XLSX/PDF async + signed download + audit.
- Grafik tren pada dashboard + filter tanggal/kelas/jenjang.

**Deliverable:** laporan cocok dengan dataset uji yang ditetapkan [PRD Sprint 4 DoD].
**Test plan:** **golden dataset** (1 bulan, 3 kelas, kasus libur, pindah kelas, izin, koreksi, pulang awal, terlambat) dengan angka laporan yang dihitung manual dan disepakati; ekspor di-parse ulang dan dibandingkan; uji otorisasi ekspor; rekalkulasi idempoten (jalankan 2x → hasil sama).
**Acceptance:** D3, D4, D6, F1–F5.

---

## Tahap 6 — Hardening & Pilot

**Dependensi:** Tahap 1–5; D-23 (hosting), D-26 (retensi).
**Pekerjaan:**
- Review keamanan internal + pentest eksternal (rekomendasi), DAST (OWASP ZAP), audit dependensi.
- Infrastruktur produksi: HTTPS, secret manager, backup + PITR, **uji restore**, monitoring & alert, runbook.
- Penerapan job retensi sesuai keputusan.
- Dokumentasi admin & petugas (Bahasa Indonesia), prosedur manual saat gangguan.
- Uji lapangan satu sekolah [PRD 13.4], perbaikan temuan.

**Deliverable:** lingkungan produksi pilot + laporan uji lapangan + persetujuan prosedur oleh sekolah pilot.
**Acceptance:** G3, G5, G6 + seluruh acceptance kritis lulus di lingkungan pilot [PRD 17].

---

## Tahap 7 — Modul Verifikasi Wajah (pasca-MVP, bersyarat)

**Dependensi:** seluruh prasyarat di `DECISIONS.md` §5 disetujui secara tertulis.
Tidak ada pekerjaan kode sebelum gerbang tersebut terpenuhi. Bila disetujui: migrasi `biometric_profiles`, perluasan `CHECK method` dengan `face`, modul terpisah di balik interface `BiometricVerifier`, feature flag per sekolah yang hanya bisa diaktifkan bila checklist kepatuhan tercatat, serta uji FAR/FRR & spoofing di lapangan.

---

## Pasca-MVP lain (backlog, tidak dijadwalkan)
Portal orang tua & notifikasi, WhatsApp resmi, multi-terminal, offline dengan rekonsiliasi, integrasi SIAKAD, billing, jadwal kelompok/khusus, akun multi-sekolah, websocket realtime, partisi tabel event.
