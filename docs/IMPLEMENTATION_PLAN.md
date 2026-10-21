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
- [x] E2E di CI: job `e2e` (PostgreSQL, Redis, Mailpit, API, worker, migrasi + DemoSeeder, lalu Playwright di image resmi `playwright:v1.63.0-noble`; trace diunggah bila gagal). Versi image harus sama dengan `@playwright/test`.
- [x] Penutupan Tahap 1: CI hijau penuh di PR #1 (API, web, Docker, hygiene, E2E). Temuan dari E2E di CI: folder `storage/framework/*` harus ter-track agar checkout bersih bisa merender email.

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

**Progres Tahap 2** (dikerjakan per irisan, tiap irisan: migrasi + RLS → API + test → kontrak OpenAPI → UI → E2E):
- [x] **2A — Tahun ajaran, guru, kelas.** Tabel `teachers`, `academic_years`, `classes` (FK komposit, exclusion tumpang tindih tanggal, satu tahun aktif, RLS dipaksa); API `academic-years` (+ aktivasi), `teachers`, `classes`; UI `/schools/[code]/{academic-years,teachers,classes}`; izin `school.academic.view|manage`; data demo di `DemoSeeder`; E2E `tahap2-akademik.spec.ts`. Lokalisasi pesan validasi/autentikasi ke bahasa Indonesia (`apps/api/lang/id`) ikut selesai.
- [x] **2B — Siswa, penempatan, pindah kelas.** Tabel `students` (NIS unik, NISN 10 digit unik, indeks trigram nama) dan `class_enrollments` (exclusion: satu siswa tidak di dua kelas pada tanggal yang sama); API `students` (daftar dengan pencarian/filter status/kelas, detail beserta riwayat, ubah) dan `POST students/{id}/enrollments`; UI `/schools/[code]/students`; izin `school.students.view|manage`; E2E `tahap2-siswa.spec.ts`.
- [x] **2C — Impor siswa CSV/XLSX.** Unggah → validasi async lewat job antrean → preview baris valid/gagal → commit baris valid saja dalam satu transaksi (D-15); templat CSV; unduh baris gagal sebagai file perbaikan; tabel staging `import_batches`/`import_rows` (RLS); UI `/schools/[code]/students/import`; E2E `tahap2-impor.spec.ts`.
- [x] **2D — Libur, jadwal berversi, kalender.** Tabel `holidays` dan `schedules` (scope sekolah/tingkat/kelas, exclusion: satu jadwal aktif per scope+hari pada tanggal mana pun, urutan jam dan konsistensi scope di CHECK); API `holidays`, `schedules` (terbit versi baru), `calendar`; `ScheduleResolver` (libur > kelas > tingkat > sekolah, dimuat dua query per rentang); UI `/schools/[code]/{schedules,holidays,calendar}`; izin `school.schedule.view|manage`; E2E `tahap2-jadwal.spec.ts`.
- [x] **2E — Scope wali kelas dan reset password oleh admin.** `ClassScope` (scoped per request): wali kelas (tanpa peran Admin/Kepala Sekolah) hanya melihat kelas yang diampu guru aktif yang tertaut ke keanggotaannya, siswa dengan penempatan berjalan di kelas itu, dan kalender kelas itu; kelas/siswa lain 404, daftar guru hanya dirinya. Izin lihat akademik/siswa/jadwal diberikan ke wali kelas. `POST members/{membership}/password-reset` (izin `school.members.manage`, alasan wajib, diaudit): mengirim tautan reset ke email pemilik akun; UI di halaman Anggota; akun demo `wali@demo.test`; E2E `tahap2-wali-kelas.spec.ts`.
- [x] **2F-a — Pembersihan staging impor (D-26).** `imports:purge` (default 30 hari, `--days`) menghapus batch, baris (cascade), dan file unggahan per sekolah di bawah RLS; terjadwal harian 02:30 lewat service `scheduler` (`schedule:work`) di `compose.yaml`; data siswa dan audit tidak tersentuh.
- [ ] 2F-b — Foto siswa (butuh keputusan penyimpanan objek: disk privat vs S3/R2). **Ditunda keluar dari penutupan Tahap 2**; dikerjakan setelah keputusan penyimpanan.
- [ ] Penutupan Tahap 2: PR `tahap-2/data-sekolah` → `main` dengan CI hijau (API, web, Docker, hygiene, E2E). Verifikasi lokal: 580 test API, Larastan, typecheck, lint, `build` web, 6 E2E.

Keputusan 2A (sudah diterapkan, tercatat agar tidak ditebak ulang):
- Tanpa endpoint DELETE untuk data akademik: guru dinonaktifkan, kelas diarsipkan, tahun ajaran dinonaktifkan lewat aktivasi tahun lain (konvensi "data inti tidak di-hard-delete").
- Guru yang masih menjadi wali kelas **aktif** tidak bisa dinonaktifkan (409); kelas harus dialihkan/diarsipkan dulu.
- Tahun ajaran sebuah kelas tidak bisa diganti setelah dibuat (penempatan siswa dan absensi menempel padanya).
- Wali kelas (`homeroom_teacher`) melihat data akademik hanya untuk kelasnya (2E). Kepala Sekolah hanya melihat.
- Penautan guru ke akun login (`membership_id`) opsional dan harus keanggotaan sekolah yang belum berakhir (D-07).

Keputusan 2B:
- Pindah kelas menutup penempatan lama sehari sebelum tanggal mulai yang baru; tanggal mulai harus dalam tahun ajaran kelas tujuan dan setelah mulai penempatan sebelumnya. Kelas tujuan harus aktif.
- Tabel `class_enrollments` tidak punya kolom `status` (berbeda dari DATABASE_DESIGN): status penempatan diturunkan dari `end_date` (kosong = berjalan), agar tidak ada dua sumber kebenaran.
- Siswa yang diubah menjadi nonaktif/lulus/pindah sekolah otomatis ditutup penempatannya (hari ini); siswa tidak aktif tidak bisa ditempatkan. Siswa tidak pernah dihapus.
- `current_class` memakai tanggal hari ini di zona waktu sekolah. Penempatan dibuat lewat `POST /students/{id}/enrollments` (bukan `POST /enrollments` seperti di ARCHITECTURE) karena selalu terikat satu siswa.
- Foto siswa ditunda (2F).

Keputusan 2E:
- Admin tidak pernah menetapkan password (ADR 0007/D-37): "reset oleh admin" berarti admin memicu email tautan reset ke pemilik akun. Hanya untuk anggota aktif berakun aktif, bukan diri sendiri; broker membatasi satu tautan per menit per akun (429 `RESET_THROTTLED`); audit `school.member.password_reset_requested` tanpa token.
- Pembatasan kelas berlaku untuk peran wali kelas saja; bila pengguna juga Admin/Kepala Sekolah, tidak dibatasi. Guru nonaktif atau tanpa tautan akun tidak membuka kelas apa pun. Jadwal dan hari libur tetap terlihat seluruhnya (matriks ARCHITECTURE §7).
- Penempatan yang sudah berakhir (siswa pindah/lulus) menutup akses wali kelas ke siswa itu; riwayat kelas lama hanya terlihat lewat detail siswa yang masih dalam kelasnya.
- Peran petugas piket ("cari minimal") belum diberi akses data; menunggu Tahap 3-4.

Keputusan 2D:
- Menerbitkan jadwal = `POST /schedules` dengan `effective_from`: versi sebelumnya yang masih berlaku ditutup sehari sebelumnya, semua hari dalam satu transaksi (gagal satu, gagal semua). Ditolak (409 `SCHEDULE_CONFLICT`) bila sudah ada versi yang berlaku mulai tanggal itu atau setelahnya. Jam tidak pernah diubah; `PATCH` hanya menutup (`effective_until`) atau menonaktifkan sebuah baris. Trigger imutabilitas "jadwal yang dirujuk sesi" menunggu tabel sesi (Tahap 3-4).
- Jadwal tidak boleh melewati tengah malam; `late_grace_minutes` 0–120. Jadwal tingkat/kelas hanya berlaku bila ada jadwal itu; tanpa jadwal pada suatu hari = tidak ada sesi.
- Libur berlaku seluruh sekolah (D-24) dan boleh dihapus (diaudit dengan isinya); libur yang beririsan dibiarkan.
- Kalender tanpa `class_id` hanya menghitung jadwal sekolah; dengan `class_id` memakai prioritas kelas > tingkat > sekolah. Hanya yang punya izin melihat data akademik bisa memilih kelas di UI.
- Wali kelas kini boleh melihat jadwal (2E); petugas belum.

Keputusan 2C:
- Batas file: 5 MB, 5.000 baris, XLSX maks 50 MB terdekompresi dan 200 komponen (anti zip bomb); isi file diperiksa (XLSX harus ZIP, CSV tidak boleh berisi byte NUL atau ZIP). Sel selalu dibaca sebagai teks; rumus tidak dievaluasi. Hanya lembar pertama XLSX yang dibaca.
- Pemisah CSV (`,` atau `;`) dideteksi otomatis; tanggal lahir menerima `TTTT-BB-HH` dan `HH/BB/TTTT`; nama kolom dikanonikalkan (mis. "Nomor Induk", `NIS` → `nomor_induk`).
- Kolom `kelas` opsional: dicocokkan dengan kelas aktif di tahun ajaran aktif; penempatan mulai tanggal mulai tahun ajaran itu.
- Commit memvalidasi ulang tiap baris (data bisa berubah sejak preview); baris yang bentrok menjadi `skipped`. Satu audit tingkat batch (`school.import.committed`: jumlah, nama file, hash); siswa hasil impor tidak diaudit satu per satu.
- File unggahan disimpan di disk privat `local` (`storage/app/private/imports/{school}/`); migrasi ke object storage mengikuti keputusan penyimpanan objek. Penghapusan terjadwal staging dan file (D-26, 30 hari) ada di 2F-a. **Belum ada**: pembatalan impor; dicatat untuk tahap hardening.

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

**Progres Tahap 3** (kamera laptop dipakai untuk uji; pemindai keyboard-wedge didukung sejak awal untuk perangkat sungguhan):
- [x] **3A — Kredensial QR.** Tabel `student_credentials` (hash HMAC-SHA256 berversi, satu QR aktif per siswa, status hanya maju); terbit, terbit ulang, blokir/buka blokir, cabut; kartu per kelas (maks 200); token hanya tampil sekali; kartu dirender dan dicetak di browser (CSS cetak) karena server hanya menyimpan hash, sehingga tidak ada job PDF; izin `school.credentials.view|manage`.
- [x] **3B — Perangkat terminal.** Tabel `devices` (kode pairing 8 karakter berlaku 10 menit, hanya hash; token perangkat 256-bit, hanya hash); fungsi `SECURITY DEFINER` `claim_device_pairing`/`find_device_by_token_hash` untuk pencarian sebelum konteks sekolah dikenal; guard `AuthenticateDevice` (cookie HttpOnly SameSite=Strict + header `X-Sentra-Terminal`); heartbeat, pencabutan langsung efektif (`DEVICE_REVOKED`); batas perangkat per sekolah `schools.device_limit` (bawaan 1, D-30); izin `school.devices.view|manage`; UI `/schools/[code]/devices`.
- [x] **3C — Inti absensi.** Tabel `attendance_sessions`, `attendance_events` (append-only lewat trigger, indeks unik satu accepted per siswa per hari per jenis, kunci idempotensi unik), `daily_attendance`; trigger yang membekukan isi jadwal yang sudah dirujuk sesi; `AttendanceStateMachine` murni (tabel ARCHITECTURE §8.1); `RecordScanAction`; endpoint terminal `POST scans` dan `GET recent`; uji konkurensi nyata (8 proses `pcntl_fork`, tepat 1 accepted).
- [x] **3D — App terminal `/terminal`.** Pairing, pemindai kamera (jsQR) dan keyboard-wedge, kolom tempel kode untuk uji, hasil layar penuh berwarna, daftar transaksi terakhir, jam sinkron server, heartbeat, percobaan ulang jaringan dengan kunci idempotensi yang sama; E2E `tahap3-terminal.spec.ts`. Pembacaan kamera sungguhan belum diuji otomatis (hanya manual).
- [x] **3E — Dashboard harian.** `GET attendance/today` (izin `school.attendance.view`: Admin, Kepala Sekolah, Wali Kelas dibatasi `ClassScope`, Petugas Piket); kartu hadir/terlambat/belum tercatat/pulang, transaksi terbaru, peringatan perangkat offline; polling 10 detik di halaman Ringkasan.
- [ ] Penutupan Tahap 3: commit, PR `tahap-3/qr-terminal` → `main`, CI hijau. **Belum ada**: CSP ketat untuk `/terminal`, simulasi jaringan putus di E2E, uji dengan perangkat pemindai fisik.

Keputusan Tahap 3:
- Nilai scan mentah tidak pernah disimpan atau dicatat di log; kartu tak dikenal hanya meninggalkan sidik jari HMAC terpotong (8 byte). Layar terminal hanya menampilkan nama depan + inisial nama belakang.
- Hasil `duplicate`, `rejected`, dan `needs_review` tetap menjadi event diagnostik dan tidak pernah dihitung hadir; scan ulang tidak pernah menjadi check-out sebelum jendela pulang dibuka atau sebelum 5 menit sejak check-in.
- Waktu absensi selalu waktu server dalam zona waktu sekolah; `captured_at` klien hanya informasi diagnostik.
- Baris `daily_attendance` dibuat saat scan pertama yang lolos penyaringan jadwal (termasuk `TOO_EARLY`) dengan status `belum_tercatat`; hanya check-in/check-out accepted yang mengubahnya.
- Terminal belum mendukung antrean offline: scan saat jaringan putus gagal dengan pesan jelas dan diulang 2 kali dengan kunci idempotensi yang sama.
- `last_seen_ip` perangkat tidak disimpan (kebijakan retensi); `last_seen_at` diperbarui paling cepat tiap 30 detik; online = terlihat dalam 2 menit.

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

## Fitur tambahan: jadwal mengajar dan impor guru (dikerjakan setelah Tahap 6)

- [x] Mata pelajaran (`subjects`), mapel per guru (`teacher_subjects`), dan jam mengajar (`teaching_slots`: kelas × mapel × guru × hari × jam) dengan exclusion constraint yang menolak guru atau kelas dobel pada jam beririsan; RLS per sekolah. Email kontak guru (`teachers.email`).
- [x] Impor guru dan impor jadwal mengajar memakai alur staging yang sama dengan impor siswa (`ImportKind` per jenis; `import_batches.type` = `students`/`teachers`/`teaching_schedule`). Mapel baru dibuat otomatis saat commit.
- [x] API: `/subjects`, `/teaching-slots`, `/imports/teachers`, `/imports/teaching-schedule`, `/imports/template?type=`. UI: papan jadwal per kelas/guru, halaman mapel, halaman impor (komponen `ImportWizard` dipakai bersama impor siswa).
- Belum: absensi per jam pelajaran, akun login otomatis dari impor guru, jadwal berversi per semester.

---

## Pasca-MVP lain (backlog, tidak dijadwalkan)
Portal orang tua & notifikasi, WhatsApp resmi, multi-terminal, offline dengan rekonsiliasi, integrasi SIAKAD, billing, jadwal kelompok/khusus, akun multi-sekolah, websocket realtime, partisi tabel event.
