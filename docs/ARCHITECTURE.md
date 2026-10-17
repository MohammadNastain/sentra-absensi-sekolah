# ARCHITECTURE — Sentra Absensi Sekolah

**Status:** Draf tahap 1 — menunggu tinjauan
**Sumber kebutuhan:** `PRD_Sentra_Absensi_Sekolah_v1.md` (v1.0, 17 Oktober 2026)
**Dokumen terkait:** `DATABASE_DESIGN.md`, `IMPLEMENTATION_PLAN.md`, `TESTING_STRATEGY.md`, `DECISIONS.md`

Penanda yang dipakai di dokumen ini:
- **[PRD x.y]** — kebutuhan diambil langsung dari bagian PRD.
- **[REKOMENDASI]** — usulan arsitek, belum diputuskan pemilik produk.
- **[TERBUKA D-nn]** — keputusan belum pasti; detail di `DECISIONS.md`.

---

## 1. Hasil Pemeriksaan Repository dan Lingkungan (17 Okt 2026)

| Item | Temuan | Dampak |
|---|---|---|
| Isi repository | Hanya `PRD_Sentra_Absensi_Sekolah_v1.md`. Tidak ada kode, konfigurasi, atau dependensi. | Tidak ada yang berisiko tertimpa. Struktur dapat dirancang dari nol. |
| Nama file PRD | Di instruksi disebut `PRD_Sentra_Absensi_Sekolah.md`; yang ada `..._v1.md`. | Hanya catatan. Isi PRD dianggap final v1.0. |
| Git | Folder **belum** menjadi repository git. | Perlu `git init` sebelum tahap implementasi (belum dilakukan). |
| Node.js / npm | v24.22.0 / 11.19.1 | Cukup untuk Next.js. pnpm belum terpasang (bisa via `corepack`). |
| PHP | Hanya PHP **8.2.12** bawaan XAMPP (build Okt 2023). Ekstensi `pdo_pgsql`, `intl`, `zip`, `gd`, `sodium` **tidak aktif**. | Tidak cocok untuk Laravel versi terbaru maupun PostgreSQL. Jangan memakai/mengubah XAMPP; siapkan runtime terpisah. |
| Composer | Tidak ada. | Wajib dipasang. |
| PostgreSQL | Tidak ada. | Wajib (fitur PostgreSQL dipakai untuk integritas data; SQLite/MySQL bukan pengganti). |
| Docker | Tidak ada. | [TERBUKA D-02] Docker Desktop (direkomendasikan) atau instalasi native. |
| Python | 3.12.10 | Tidak dibutuhkan oleh stack utama. |

Kesimpulan: implementasi belum bisa dimulai sebelum lingkungan pengembangan disiapkan (lihat Tahap 0 di `IMPLEMENTATION_PLAN.md`).

**Pembaruan Tahap 0 (17 Okt 2026):**
- `git init` sudah dilakukan.
- Struktur monorepo, `compose.yaml`, init database, skrip verifikasi, dan CI sudah dibuat.
- WSL 2 dan Docker Desktop **masih belum terpasang** (lihat `DEV_SETUP.md`), jadi verifikasi runtime belum dijalankan.
- Keputusan pemilik produk dicatat di `docs/adr/0001`–`0006`.

---

## 2. Gambaran Sistem

```text
                         ┌──────────────────────────────────────────────┐
  Gerbang sekolah        │                 Cloud / VPS                  │
 ┌───────────────────┐   │                                              │
 │ USB 2D QR scanner │   │  ┌────────────┐   ┌──────────────────────┐   │
 │ USB RFID reader   │──▶│  │ Terminal   │   │  Laravel API          │   │
 │ (keyboard wedge)  │   │  │ web app    │──▶│  /api/v1/terminal/*   │   │
 │ Mini PC/tablet    │   │  │ (kiosk)    │   │  device guard         │   │
 │ browser kiosk     │   │  └────────────┘   │                       │   │
 └───────────────────┘   │                   │  /api/v1/*            │   │
                         │  ┌────────────┐   │  session guard        │──▶ PostgreSQL (RLS)
  Admin / Kepsek /       │  │ Dashboard  │──▶│  (Sanctum SPA)        │──▶ Redis (session, queue,
  Wali kelas / Piket ───▶│  │ Next.js    │   │                       │        rate limit, cache)
                         │  └────────────┘   │  Queue worker         │──▶ Object storage privat
                         │                   │  Scheduler            │     (foto, impor, ekspor)
                         │                   └──────────────────────┘   │
                         └──────────────────────────────────────────────┘
```

Prinsip yang mengikat seluruh desain [PRD 6.3]:
1. **Backend adalah satu-satunya sumber kebenaran** untuk identitas, waktu, jadwal, dan status. Terminal hanya mengirim input mentah dan menampilkan hasil.
2. **Akses tenant selalu diotorisasi oleh identitas yang terautentikasi.** Device terikat ke satu sekolah. User memilih sekolah lewat path `/schools/{school}`, dan akses hanya diberikan bila user memiliki keanggotaan aktif di sekolah itu (ADR 0004).
3. **Event mentah tidak pernah diubah atau dihapus.** Semua koreksi bersifat tambahan (append-only) dan teraudit.
4. **Database adalah garis pertahanan terakhir**: constraint, foreign key komposit, partial unique index, exclusion constraint, dan Row-Level Security.
5. **Modul wajah terisolasi dan tidak dibangun di MVP.** Alur QR/RFID/bantuan manual tidak bergantung padanya.

---

## 3. Stack Teknologi

| Lapisan | Pilihan | Alasan / catatan |
|---|---|---|
| Backend API | **Laravel** (versi stabil terbaru saat Tahap 0, dipin di `composer.lock`), **PHP 8.4** | Sesuai [PRD 6.1]. PHP 8.2 (XAMPP) mendekati akhir dukungan keamanan, jadi tidak dipakai. |
| Database | **PostgreSQL 17 atau 18** | Dibutuhkan untuk `timestamptz`, partial unique index, exclusion constraint (`btree_gist`), `citext`, `pg_trgm`, RLS. |
| Dashboard | **Next.js (App Router) + TypeScript** | Sesuai [PRD 6.1]. Data diambil dari sisi klien dengan TanStack Query; server component hanya untuk shell. |
| Terminal | **App web terpisah** (Next.js, ekspor statis) di subdomain sendiri | [REKOMENDASI, TERBUKA D-03] Pisah origin dari dashboard: bundle kecil, cookie terpisah, CSP lebih ketat. |
| Auth dashboard | **Laravel Sanctum, mode SPA (cookie sesi HttpOnly + CSRF)** | Token tidak disimpan di JavaScript. Wajib API dan dashboard berada di domain induk yang sama. |
| Auth terminal | **Guard `device` khusus**: token acak 256-bit, disimpan sebagai hash di DB, dikirim lewat cookie HttpOnly | [REKOMENDASI, TERBUKA D-04] Dapat dicabut, terikat ke satu device dan satu sekolah. |
| Queue/cache/session/rate limit | **Redis** | Impor, ekspor, materialisasi harian. |
| Penyimpanan file | **Object storage S3-compatible privat** (MinIO untuk dev) | Akses lewat signed URL berumur pendek [PRD 8.1]. |
| QR (generate) | Library QR PHP standar (mis. `chillerlan/php-qrcode` atau `bacon/bacon-qr-code`), dipilih di Tahap 3 | Isi QR berupa token acak, bukan data pribadi [PRD 4.2]. |
| Impor/ekspor spreadsheet | Library streaming (mis. OpenSpout) | Hemat memori untuk file besar; dievaluasi di Tahap 2. |
| PDF | Dievaluasi di Tahap 5 (dompdf vs renderer berbasis Chromium) | Trade-off: kesederhanaan vs fidelitas layout. |
| Kontrak API | OpenAPI 3.1 dibangkitkan dari kode Laravel, tipe TypeScript dibangkitkan untuk frontend | Satu sumber kontrak, mencegah drift. |
| Monorepo JS | pnpm workspaces | Berbagi tipe API dan komponen UI antara dashboard dan terminal. |

---

## 4. Struktur Folder (usulan)

```text
Sentra_absensi_sekolah/
├── PRD_Sentra_Absensi_Sekolah_v1.md
├── docs/
│   ├── ARCHITECTURE.md
│   ├── DATABASE_DESIGN.md
│   ├── IMPLEMENTATION_PLAN.md
│   ├── TESTING_STRATEGY.md
│   ├── DECISIONS.md               # register keputusan terbuka & ADR
│   ├── adr/                       # Architecture Decision Records setelah diputuskan
│   ├── api/openapi.yaml           # dibangkitkan, di-commit untuk review diff
│   ├── hardware/                  # protokol & hasil uji reader/scanner
│   └── ops/                       # runbook backup, restore, insiden, prosedur manual petugas
├── apps/
│   ├── api/                       # Laravel
│   │   ├── app/
│   │   │   ├── Domain/
│   │   │   │   ├── Tenancy/       # School, SchoolSettings, TenantContext, RLS session setter
│   │   │   │   ├── Identity/      # User, Role, Permission enum, Policies
│   │   │   │   ├── Academic/      # AcademicYear, SchoolClass, Teacher, Enrollment
│   │   │   │   ├── Students/      # Student, photo handling
│   │   │   │   ├── Imports/       # ImportBatch, row validators, commit
│   │   │   │   ├── Scheduling/    # Schedule, Holiday, ScheduleResolver
│   │   │   │   ├── Credentials/   # StudentCredential, token generator, hasher, normalizer RFID
│   │   │   │   ├── Devices/       # Device, pairing, heartbeat, DeviceGuard
│   │   │   │   ├── Attendance/    # RecordScan, AssistedAttendance, DailyAttendanceCalculator,
│   │   │   │   │                  # Adjustments, ReviewQueue, DayClose
│   │   │   │   ├── Leave/         # LeaveRequest
│   │   │   │   ├── Reporting/     # query objects, exporters
│   │   │   │   └── Audit/         # AuditLogger, redaction rules
│   │   │   ├── Http/              # Controllers tipis, FormRequests, Resources, Middleware
│   │   │   └── Support/           # Clock (dapat diinjeksi), ProblemDetails, dsb.
│   │   ├── database/migrations/   # migrasi SQL eksplisit untuk constraint PostgreSQL
│   │   ├── database/factories/
│   │   ├── database/seeders/      # DemoSeeder diberi label jelas, tidak dijalankan di produksi
│   │   └── tests/{Unit,Feature,Database,Authorization,Tenancy}/
│   ├── web/                       # Next.js dashboard
│   │   └── src/{app,features,components,lib}/
│   └── terminal/                  # Next.js kiosk (static export)
│       └── src/{app,scanner-input,states,lib}/
├── packages/
│   ├── api-client/                # tipe + klien API hasil generate dari OpenAPI
│   └── ui/                        # komponen bersama (status badge dengan ikon+teks, dll.)
├── e2e/                           # Playwright
├── load-tests/                    # k6
├── infra/
│   ├── docker/                    # compose untuk dev: pgsql, redis, minio, mailpit
│   └── deploy/                    # konfigurasi deploy (Tahap 6)
└── .github/workflows/             # CI
```

**Implementasi (17 Okt 2026):** model Eloquent ditempatkan di `app/Models` (konvensi Laravel; factory, Fortify, dan auth bekerja tanpa konfigurasi tambahan). `Domain/*` berisi enum, action, konteks tenant, audit, dan aturan domain.

Logika bisnis ditempatkan di kelas **Action/Service** dalam `Domain/*` (mis. `RecordScanAction`), bukan di controller. Tujuannya agar bisa diuji secara unit dengan `Clock` yang diinjeksi.

---

## 5. Multi-Tenancy dan Isolasi Data

**Model:** satu database, satu skema, kolom `school_id` di setiap tabel milik sekolah [PRD 11.3]. Isolasi ditegakkan berlapis. Satu lapis yang gagal tidak boleh langsung membocorkan data.

| Lapis | Mekanisme | Mencegah |
|---|---|---|
| 1. Resolusi tenant | User: `{school}` dari path divalidasi terhadap `school_memberships` aktif di **setiap request** (bukan anggota → 404). Device: `school_id` dari device. Field `school_id` di body selalu diabaikan. | Manipulasi tenant lewat URL/body; tab tertukar. |
| 2. Query scoping | Trait `BelongsToSchool`: global scope `where school_id = ?` dan pengisian otomatis saat create. Model lintas tenant gagal dibuat tanpa konteks (exception, bukan diam). | Lupa filter di query. |
| 3. Route model binding | Binding diselesaikan melalui query ter-scope. ID milik sekolah lain menghasilkan **404**, bukan 403. | IDOR dan enumerasi ID [PRD 12.A]. |
| 4. Otorisasi | Laravel Policy per resource. Wali kelas dibatasi ke kelas yang ditugaskan. | Akses lintas peran di dalam satu sekolah. |
| 5. Foreign key komposit | Contoh: `class_enrollments (school_id, student_id) → students (school_id, id)`. | Bug aplikasi yang menghubungkan data dua sekolah. |
| 6. PostgreSQL RLS | Policy `school_id = current_setting('app.school_id')::uuid` pada tabel tenant. Role DB aplikasi bukan owner dan tanpa `BYPASSRLS`. | Kebocoran saat lapis 1–4 gagal (query mentah, report builder). [REKOMENDASI, TERBUKA D-06] |
| 7. Pengujian otomatis | Matriks isolasi: setiap route dengan parameter ID diuji memakai ID tenant lain. | Regresi. |

**Catatan implementasi RLS:**
- `app.school_id` diset di awal request (`set_config(..., false)`) dan di-reset saat request selesai. Koneksi persisten PDO dan PgBouncer mode *transaction pooling* **dilarang** selama RLS bergantung pada setting sesi.
- Job queue wajib membawa `school_id` dan menyetel konteks sebelum query (base class `TenantAwareJob`).
- Operasi platform (Super Admin) hanya menyentuh tabel `schools`, yang tidak memakai RLS tenant, dan tabel platform. Super Admin **tidak** memiliki akses ke data siswa secara default [REKOMENDASI, TERBUKA D-21].
- Migrasi dijalankan dengan role owner terpisah.

---

## 6. Autentikasi

### 6.1 Pengguna dashboard
- Login memakai email dan password, lewat Sanctum SPA (cookie sesi HttpOnly, `Secure`, `SameSite=Lax`, proteksi CSRF).
- Email unik global (case-insensitive) — **diputuskan**. Identitas (`users`) terpisah dari keanggotaan sekolah (`school_memberships`) dan peran (`membership_roles`). Satu akun dapat mengakses beberapa sekolah (ADR 0004).
- Rate limiting: per kombinasi IP+email dan per IP [PRD 8.1].
- Sesi diperbarui (regenerate) saat login. Idle 60 menit, maksimum 12 jam. Logout menghapus sesi di server.
- User `disabled` ditolak saat login **dan** pada setiap request. Keanggotaan `suspended`/`ended` ditolak pada setiap request ke sekolah tersebut [PRD 12.A].
- Akses ke sekolah berstatus `suspended` ditolak [TERBUKA D-22].
- 2FA TOTP untuk Super Admin wajib di MVP; untuk Admin Sekolah opsional [TERBUKA D-25].
- Admin Sekolah mengelola **keanggotaan**, bukan identitas: tidak bisa mengganti password/email user. User baru masuk lewat **undangan email**; reset password mandiri lewat email [TERBUKA D-37, detail di `TAHAP_1_FONDASI.md`].

### 6.2 Terminal (device)
1. Admin membuat device di dashboard. Sistem membuat **kode pairing sekali pakai** (8 karakter, berlaku 10 menit, disimpan sebagai hash).
2. Operator memasukkan kode di terminal. API memverifikasi kode lalu menerbitkan token device (256-bit acak, DB hanya menyimpan hash SHA-256) dalam cookie HttpOnly di origin terminal.
3. Setiap request terminal: guard `device` mencocokkan hash, memeriksa `status = active`, lalu menetapkan `school_id` dari device dan memperbarui `last_seen_at` (dibatasi, mis. maks 1x/30 detik).
4. Pencabutan device langsung efektif. Token yang dicabut ditolak dengan kode `DEVICE_REVOKED` [PRD 13.3].
5. Terminal **tidak pernah** memegang kredensial admin [PRD 6.3]. Token device hanya memberi akses ke endpoint `/terminal/*`.

### 6.3 Sesi bantuan petugas di terminal (kartu tertinggal)
- Petugas login di terminal (email+password, atau PIN jika kelak diputuskan [TERBUKA D-31]). Hasilnya **sesi bantuan** yang terikat ke device itu, berumur pendek (mis. 10 menit idle, 30 menit maksimum), dan hanya membawa izin `attendance.assist`.
- Sesi bantuan juga tersedia dari dashboard (perangkat petugas sendiri) dengan aturan audit yang sama.
- Setiap pencatatan bantuan menyimpan `created_by_user_id`, `device_id` (jika di terminal), alasan, dan metode verifikasi [PRD 4.4].

---

## 7. Otorisasi (RBAC + scope)

Role bersifat tetap di MVP (di-seed, tidak dapat diubah per sekolah). Izin didefinisikan sebagai enum di kode dan diperiksa melalui Policy/Gate. Role melekat pada **keanggotaan sekolah**, bukan pada user. Satu user dapat memiliki beberapa role di satu sekolah dan role berbeda di sekolah lain [PRD 7.1, ADR 0004]. Rincian izin Tahap 1 ada di `TAHAP_1_FONDASI.md` §5.

| Izin \ Role | Super Admin | Admin Sekolah | Kepala Sekolah | Wali Kelas | Petugas Piket | Operator Terminal |
|---|---|---|---|---|---|---|
| Kelola tenant/sekolah (platform) | ✔ | – | – | – | – | – |
| Profil & pengaturan sekolah | – | ✔ | lihat | – | – | – |
| Siswa, kelas, guru, tahun ajaran, impor | – | ✔ | lihat | lihat (kelasnya) | lihat (cari minimal) | – |
| Jadwal & hari libur | – | ✔ | lihat | lihat | lihat | – |
| Kredensial QR/RFID (terbit, blokir, ganti) | – | ✔ | – | – | blokir saja [TERBUKA] | – |
| Device (daftar, pairing, cabut) | – | ✔ | – | – | – | lihat status |
| Dashboard harian | – | ✔ | ✔ | kelasnya | ✔ | – |
| Transaksi terbaru | – | ✔ | ✔ | kelasnya | ✔ | ✔ (data minimal) |
| Mode bantuan (kartu tertinggal) | – | ✔ | – | – | ✔ | – |
| Antrean review (`needs_review`) | – | ✔ | – | – | ✔ | – |
| Ajukan koreksi | – | ✔ | – | kelasnya | ✔ | – |
| Setujui koreksi / izin / finalisasi alpa | – | ✔ | ✔ [TERBUKA] | kelasnya [TERBUKA D-12] | – | – |
| Laporan & ekspor | – | ✔ | ✔ | kelasnya | harian saja | – |
| Laporan transaksi ditolak/duplikat | – | ✔ | ✔ | – | ✔ | – |
| Pengguna & role | – | ✔ | – | – | – | – |
| Audit log | – | ✔ | ✔ | – | – | – |

Scope wali kelas diturunkan dari `classes.homeroom_teacher_id → teachers.user_id`. Sel bertanda [TERBUKA] perlu dikonfirmasi pemilik produk.

---

## 8. Alur Inti: Pemrosesan Scan

Endpoint: `POST /api/v1/terminal/scans`. Seluruh langkah 3–9 berjalan dalam **satu transaksi database** [PRD 7.2].

```text
Input: device (dari guard), raw_value, idempotency_key (UUID dari terminal), client_captured_at (diagnostik saja)

1. Rate limit per device.
2. now ← Clock server (NTP); local_date ← now di zona waktu sekolah.          [PRD 5.1]
3. Idempotensi: jika (school_id, idempotency_key) sudah ada → kembalikan respons tersimpan apa adanya.
4. Klasifikasi input: pola token QR (prefiks versi, mis. "SA1.") atau UID RFID (dinormalisasi
   sesuai profil reader yang dipilih).                                       [TERBUKA D-18]
5. Lookup kredensial: HMAC(raw) dicocokkan dalam school_id device.
     tidak ada → rejected UNKNOWN_CREDENTIAL (tidak membocorkan apakah token milik sekolah lain)
     blocked/revoked → rejected CREDENTIAL_BLOCKED
     siswa tidak aktif / tidak punya enrollment aktif pada local_date → rejected STUDENT_INACTIVE
6. Resolusi jadwal: hari libur → rejected HOLIDAY; tidak ada jadwal → rejected NO_SCHEDULE.
   Prioritas: jadwal kelas > jadwal jenjang > jadwal sekolah, sesuai tanggal efektif.
7. Ambil/buat baris daily_attendance (INSERT … ON CONFLICT DO NOTHING) lalu SELECT … FOR UPDATE
   → serialisasi per siswa per hari.
8. Mesin keadaan (lihat 8.1) → event_type + result.
9. INSERT attendance_events; perbarui daily_attendance; tulis audit (jika relevan); COMMIT.
   Partial unique index (satu check_in & satu check_out accepted per siswa per hari) menjadi
   jaring pengaman terakhir; pelanggaran unik → ditangani sebagai duplicate.
10. Respons: result, code, event_type, nama, kelas, waktu lokal, URL foto bertanda tangan (jika
    diizinkan pengaturan sekolah). Tidak menampilkan alasan izin/data sensitif.  [PRD 9.3]
```

Event yang `rejected`, `duplicate`, dan `needs_review` tetap disimpan untuk diagnostik, tetapi **tidak pernah dihitung sebagai kehadiran** [PRD 7.1].

### 8.1 Mesin keadaan check-in/check-out (usulan default)

Keterangan waktu jadwal: `IN_START` (mulai check-in), `DEADLINE`, `GRACE`, `OUT_START` (mulai check-out), `OUT_SCHED` (jadwal pulang), `MIN_GAP` (jeda minimum, mis. 5 menit).

| Keadaan saat ini | Waktu scan | Hasil |
|---|---|---|
| Belum ada check-in | `< IN_START` | rejected `TOO_EARLY` [TERBUKA] |
| Belum ada check-in | `IN_START … < OUT_START` | accepted `check_in`. Hadir jika `≤ DEADLINE + GRACE`, selain itu Terlambat |
| Belum ada check-in | `≥ OUT_START` | `needs_review` (pulang tanpa catatan masuk), masuk antrean tinjauan petugas [PRD 4.5, diputuskan — ADR 0005] |
| Sudah check-in, belum check-out | `< OUT_START` atau `< check_in + MIN_GAP` | `duplicate` "Absensi masuk sudah tercatat pukul 07.08" [PRD 4.6] |
| Sudah check-in, belum check-out | `≥ OUT_START` | accepted `check_out`. Ditandai pulang awal jika `< OUT_SCHED` |
| Sudah check-out | kapan pun | `duplicate` "Absensi pulang sudah tercatat pukul 15.02" |

Akibatnya, scan ulang **tidak pernah** berubah menjadi check-out sebelum jendela pulang dibuka [PRD 4.6]. Kepulangan lebih awal (mis. sakit pukul 10.00) dicatat oleh petugas berwenang lewat mode bantuan, dengan alasan dan audit trail [diputuskan — ADR 0005].

### 8.2 Status harian
`daily_attendance` adalah **proyeksi** yang dapat dihitung ulang secara deterministik dari: event accepted, izin yang disetujui, koreksi yang disetujui, dan jadwal. Kalkulator yang sama dipakai oleh jalur scan, persetujuan izin, koreksi, dan job penutupan hari, sehingga tidak ada dua implementasi aturan yang berbeda.

- "Belum tercatat" ≠ "Alpa" [PRD 5.3, diputuskan]. Alpa ditetapkan oleh aturan sekolah setelah cutoff sebagai **alpa belum diverifikasi**, dengan pengaman kesehatan perangkat dan mekanisme verifikasi/koreksi oleh petugas [usulan D-33 — ADR 0005 §5].
- Pulang awal disimpan sebagai **atribut** (flag + status persetujuan), bukan status yang menggantikan Hadir/Terlambat [TERBUKA D-08].

---

## 9. Mode Bantuan (Kartu Tertinggal)

1. Petugas membuka sesi bantuan (§6.3).
2. Petugas mencari siswa (nama/nomor; hasil minimal: nama, kelas, nomor, foto jika diizinkan).
3. Petugas mengonfirmasi verifikasi visual (centang wajib) dan memilih alasan (enum: kartu tertinggal, kartu rusak, lainnya + catatan).
4. API menjalankan pipeline yang **sama** dengan scan (langkah 2, 5–10) dengan `method = assisted_manual`. Waktu tetap waktu server; tidak dapat diisi mundur.
5. Audit log mencatat petugas, device, alasan.
6. Laporan anomali: jumlah pencatatan bantuan per petugas dan per siswa dalam rentang tanggal, untuk mendeteksi penyalahgunaan.

Tidak ada endpoint yang memungkinkan pengetikan nama siswa lalu langsung membuat absensi tanpa sesi petugas yang terautentikasi [PRD 4.4].

**Verifikasi wajah tidak dibangun di MVP.** Nilai `face` tidak diizinkan oleh check constraint `method` sampai modul biometrik lolos gerbang kepatuhan (lihat `IMPLEMENTATION_PLAN.md` Tahap 7). Pengaturan sekolah `face_verification_enabled` tidak dibuat di MVP agar tidak memberi kesan fitur siap.

---

## 10. Integrasi Perangkat Gerbang

**Prinsip:** tidak ada klaim kompatibilitas sebelum uji fisik [PRD 4.3, 11.4].

| Aspek | Asumsi kerja (harus diverifikasi) |
|---|---|
| Antarmuka reader | Mode **USB HID keyboard wedge** (reader "mengetik" nilai lalu Enter). Pendekatan paling sederhana untuk aplikasi web tanpa driver. |
| Jenis RFID | **Belum dipilih** [TERBUKA D-18]. 125 kHz (EM4100) dan 13,56 MHz (MIFARE/NFC) tidak saling kompatibel. |
| Format UID | Berbeda antar reader (desimal 10 digit, hex, urutan byte terbalik, ada/tidaknya nol di depan). Normalizer berbasis **profil reader** yang terdokumentasi dan diuji pada perangkat nyata. |
| QR | Scanner USB 2D (keyboard wedge) direkomendasikan; kamera web via library JS sebagai alternatif [TERBUKA D-19]. |
| Layout keyboard | Reader wedge bergantung pada layout keyboard OS. Kunci layout ke US pada perangkat terminal; uji dengan karakter token QR. |
| Fokus input | Terminal menangkap input di level dokumen (bukan field tertentu). Indikator "Siap memindai" hanya tampil bila fokus aktif. Watchdog mengembalikan fokus. |
| Perangkat terminal | Belum ditetapkan (mini PC Windows/Chromebook/tablet Android) [TERBUKA D-19]. Mode kiosk browser, auto-start, tanpa akses OS bagi siswa. |

Pembedaan scanner vs ketikan manusia memakai heuristik jeda antar karakter. Ini **bukan kontrol keamanan**: UID RFID memang bukan rahasia [PRD 7.1, 8.3].

**State UI terminal** [PRD 9.3]: Siap memindai · Memproses · Berhasil (masuk/pulang, hadir/terlambat) · Sudah tercatat · Tidak dikenal · Kredensial diblokir · Jadwal tidak aktif/libur · Perlu verifikasi petugas · Koneksi bermasalah. Setiap state memakai ikon + teks + warna (tidak hanya warna) [PRD 9.4].

**Kegagalan jaringan** [PRD 11.2]: retry otomatis maks 1x dengan `idempotency_key` yang sama, timeout ±5 detik, lalu tampil "Koneksi bermasalah — absensi BELUM tercatat". Terminal **tidak pernah** menampilkan sukses tanpa respons `accepted` dari server. Jika respons hilang setelah server commit, scan ulang menghasilkan "sudah tercatat", yang tetap benar.

---

## 11. API (ringkasan kontrak)

Konvensi: REST JSON, prefiks `/api/v1`, error format **RFC 9457 (problem+json)** dengan `code` stabil, timestamp ISO-8601 UTC + field `*_local` bila relevan, daftar dengan paginasi (cursor untuk event, offset untuk master data). Semua operasi tulis yang sensitif menerima `reason` bila dibutuhkan aturan audit.

### 11.1 Auth
| Method | Path | Keterangan |
|---|---|---|
| GET | `/sanctum/csrf-cookie` | Inisialisasi CSRF |
| POST | `/api/v1/auth/login` · `/auth/logout` | Rate limited |
| GET | `/api/v1/auth/me` | User, role, izin efektif, sekolah |
| POST | `/api/v1/auth/password` | Ganti password |

### 11.2 Platform (Super Admin)
| Method | Path |
|---|---|
| GET, POST | `/api/v1/platform/schools` |
| GET, PATCH | `/api/v1/platform/schools/{school}` |
| POST | `/api/v1/platform/schools/{school}/suspend` · `/activate` |
| POST | `/api/v1/platform/schools/{school}/admins` (buat Admin Sekolah pertama) |

### 11.3 Tenant (prefiks `/api/v1/schools/{school}`, keanggotaan divalidasi per request)
Semua path di tabel ini **relatif terhadap prefiks tersebut** (D-34). Endpoint lengkap Tahap 1 (auth, undangan, anggota, audit) ada di `TAHAP_1_FONDASI.md` §6.

| Area | Endpoint utama |
|---|---|
| Sekolah | `GET/PATCH /school`, `GET/PATCH /school/settings` |
| Tahun ajaran | `CRUD /academic-years`, `POST /academic-years/{id}/activate` |
| Guru | `CRUD /teachers` |
| Kelas (rombel) | `CRUD /classes`, `GET /classes/{id}/students` |
| Siswa | `CRUD /students` (DELETE = nonaktifkan), `POST /students/{id}/status`, `PUT /students/{id}/photo`, `GET /students/{id}/attendance` |
| Penempatan | `POST /enrollments` (masuk/pindah kelas), `POST /enrollments/{id}/end` |
| Impor | `POST /imports/students` (upload), `GET /imports/{id}`, `GET /imports/{id}/rows?status=failed`, `POST /imports/{id}/commit`, `GET /imports/template` |
| Jadwal | `CRUD /schedules` (edit = versi baru berlaku mulai tanggal X), `CRUD /holidays`, `GET /calendar?month=` |
| Kredensial | `GET /students/{id}/credentials`, `POST /students/{id}/credentials/qr` (terbit/terbit ulang), `POST /students/{id}/credentials/rfid`, `POST /credentials/{id}/block` · `/unblock` · `/revoke`, `POST /credentials/qr-cards` (job PDF kartu per kelas) |
| Device | `CRUD /devices`, `POST /devices/{id}/pairing-code`, `POST /devices/{id}/revoke` |
| Absensi | `GET /attendance/events`, `GET /attendance/daily`, `POST /attendance/assisted`, `GET /attendance/review-queue`, `POST /attendance/review-queue/{eventId}/resolve`, `POST /attendance/day-finalization` |
| Koreksi | `POST /attendance/daily/{id}/adjustments`, `GET /attendance/adjustments`, `POST /attendance/adjustments/{id}/approve` · `/reject` |
| Izin/sakit | `CRUD /leave-requests`, `POST /leave-requests/{id}/approve` · `/reject` |
| Dashboard | `GET /dashboard/summary?date=&class_id=&grade_level=`, `GET /dashboard/recent-events`, `GET /dashboard/alerts` |
| Laporan | `GET /reports/{daily,monthly,class,student,lateness,status-recap,scan-history,adjustments,rejected-events}` |
| Ekspor | `POST /exports` `{report, filters, format: csv|xlsx|pdf}` → `GET /exports/{id}` → `GET /exports/{id}/download` (signed, kedaluwarsa) |
| Anggota | `GET /members`, `POST /invitations`, `PUT /members/{id}/roles`, `POST /members/{id}/suspend` · `/reactivate` · `/end` (tidak ada reset password oleh admin — ADR 0004) |
| Audit | `GET /audit-logs` |

### 11.4 Terminal (guard device)
| Method | Path | Keterangan |
|---|---|---|
| POST | `/api/v1/terminal/pair` | Kode pairing → cookie device |
| GET | `/api/v1/terminal/config` | Nama sekolah, zona waktu, waktu server, metode aktif, opsi tampil foto |
| POST | `/api/v1/terminal/heartbeat` | Indikator koneksi [PRD 3.1-21] |
| POST | `/api/v1/terminal/scans` | Inti absensi (§8) |
| GET | `/api/v1/terminal/recent` | N transaksi terakhir, data minimal |
| POST, DELETE | `/api/v1/terminal/assist/session` | Buka/tutup sesi bantuan petugas |
| GET | `/api/v1/terminal/assist/students?q=` | Pencarian minimal (perlu sesi bantuan) |
| POST | `/api/v1/terminal/assist/attendance` | Pencatatan bantuan |

Contoh respons scan:
```json
{
  "result": "duplicate",
  "code": "ALREADY_CHECKED_IN",
  "event_type": "check_in",
  "student": { "display_name": "Ahmad R.", "class_name": "7A", "photo_url": null },
  "recorded_at": "2026-10-19T00:08:12Z",
  "recorded_at_local": "07.08",
  "message": "Absensi masuk sudah tercatat pukul 07.08"
}
```

---

## 12. Audit Log
- Ditulis dalam **transaksi yang sama** dengan perubahan datanya. Jika audit gagal, perubahan dibatalkan.
- Wajib untuk: koreksi absensi, perubahan jadwal/hari libur, terbit/blokir/cabut kredensial, pairing/cabut device, perubahan user/role, impor yang di-commit, ekspor data, persetujuan izin, finalisasi alpa, penggunaan mode bantuan, pengubahan status siswa, perubahan pengaturan sekolah, aksi platform [PRD 2.2, 8.1].
- `before`/`after` disaring dengan **allowlist** per entitas. Tidak menyimpan password, token, hash kredensial, atau alasan medis lengkap.
- Append-only: role DB aplikasi hanya punya `INSERT, SELECT`; trigger menolak `UPDATE/DELETE`.

---

## 13. Pelaporan dan Ekspor
- Status akhir harian berasal dari `daily_attendance`; riwayat scan mentah dari `attendance_events`. Keduanya ditampilkan terpisah [PRD 12.F].
- Penyebut persentase kehadiran = hari efektif wajib per siswa (dimaterialisasi saat penutupan hari) [PRD 10, TERBUKA D-14].
- Ekspor berjalan sebagai job di antrean terpisah (prioritas rendah) agar tidak menghambat scan [PRD 11.1]. File disimpan privat dengan signed URL kedaluwarsa. Setiap ekspor diaudit.
- Proteksi CSV/XLSX injection: sel yang diawali `= + - @` diberi prefiks `'`.
- Otorisasi ekspor sama dengan otorisasi laporan yang mendasarinya (wali kelas hanya kelasnya).

---

## 14. Keamanan dan Privasi (ringkas)
- HTTPS/HSTS; CSP ketat (terutama terminal); header keamanan standar.
- Validasi input di FormRequest backend (frontend hanya untuk UX).
- Rahasia di environment/secret manager: `APP_KEY`, `CREDENTIAL_HMAC_KEY` (berversi), kredensial DB/storage [PRD 6.3].
- Log aplikasi: tidak mencatat body request scan, token, atau UID mentah. Ada uji otomatis untuk ini [PRD 13.3].
- Foto siswa: object storage privat, signed URL ≤ 5 menit, tampil di terminal hanya jika pengaturan sekolah mengizinkan.
- Minimisasi data: `date_of_birth` dan `nisn` opsional; tidak ada kolom yang tidak dibutuhkan PRD.
- Retensi: kebijakan default diusulkan di `DECISIONS.md` D-26, butuh persetujuan sebelum produksi [PRD 7.2].
- Lokasi data/hosting: [TERBUKA D-23] perlu tinjauan hukum, terutama untuk sekolah negeri.

---

## 15. Observabilitas dan Operasi
- Log terstruktur (JSON) dengan `request_id`, `school_id`, `device_id`, tanpa PII.
- Metrik: latensi p95 `/terminal/scans`, rasio rejected/needs_review, device offline, umur antrean job, kegagalan job penutupan hari, status backup.
- Health check: `/health` (liveness) dan `/health/ready` (DB, Redis, storage).
- Backup: base backup + WAL archiving (PITR) PostgreSQL, uji restore terjadwal [PRD 12.G].
- Runbook: gangguan internet di gerbang (prosedur kertas → input bantuan/koreksi dengan alasan), device hilang/dicuri (cabut token), kebocoran kunci HMAC (rotasi + terbit ulang QR).

---

## 16. Kapasitas — Catatan Penting untuk Pemilik Produk

Satu terminal secara fisik memproses kira-kira **1 siswa per 2–3 detik** (scan + membaca layar). Dalam jendela masuk 30 menit, kapasitasnya sekitar **600–900 siswa**. Sekolah yang lebih besar akan mengalami antrean walaupun backend cepat. Dukungan multi-terminal ada di luar MVP [PRD 3.2], tetapi skema data sudah mendukung banyak device. Sebaiknya ini menjadi kriteria pemilihan sekolah pilot. Beban backend sendiri rendah: 100 sekolah serentak setara puluhan hingga sekitar 100 request/detik, masih dalam kemampuan satu instance PostgreSQL.
