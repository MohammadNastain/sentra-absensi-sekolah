# ADR 0009 — Entitlement fitur, paket, dan override per sekolah

**Status:** Diterima (25 Okt 2026). Keputusan §"Pertanyaan terbuka" disetujui sesuai usulan: fitur dipecah seperti tabel §1; Super Admin virtual ikut terkena entitlement; sekolah yang ada memakai `legacy`; paket uji coba dan tanggal berakhir ditentukan di E5; override berbatas waktu ditunda; paket diubah lewat migration.
**Sumber:** PRD Sentra Sekolah v1.1 §5, §6, §12 (kriteria "Super Admin dan entitlement")

## Konteks

Sentra akan menjual modul berbeda per sekolah (Basic/Standard/Premium) dan menambah modul baru (LMS dan seterusnya).
Sekarang semua sekolah memperoleh semua fitur; izin (`Permission`, `school.can:*`) hanya menjawab "peran ini boleh
apa", bukan "sekolah ini membeli apa". Dua pertanyaan itu harus tetap terpisah:

- **Permission** = hak seorang pengguna di dalam sekolah (sudah ada).
- **Entitlement** = hak sebuah sekolah atas sebuah fitur (ADR ini).

Sebuah request lolos hanya bila keduanya terpenuhi.

## Keputusan

### 1. Registri fitur didefinisikan di kode
`App\Domain\Entitlement\Feature` (enum) adalah sumber kebenaran: kode fitur, induk, prasyarat (`requires`), dan status
rilis (`released`). Alasan: PRD §5.3 melarang mengaktifkan fitur yang belum ada hanya dengan mengubah flag database.
Tabel `features` hanya cermin untuk integritas FK dan tampilan, disinkronkan oleh migration/seeder, dan sebuah tes
memastikan isinya sama dengan enum.

Kode fitur memakai titik sebagai hirarki. Fitur anak otomatis butuh induknya.

| Fitur | Anak | Rute yang dicakup (sekarang) | Rilis |
|---|---|---|---|
| `attendance` | `.scan_qr`, `.scan_rfid`, `.leave`, `.corrections`, `.reports` | kehadiran, perangkat/terminal, kredensial, izin, koreksi, ekspor, jadwal kehadiran, libur, kalender | ya |
| `students` | – | siswa, impor siswa | ya |
| `teachers` | – | guru, impor guru | ya |
| `academic` | `.years`, `.classes`, `.subjects`, `.teaching_schedule` | tahun ajaran, kelas, mapel, jadwal mengajar | ya |
| `reports` | – | laporan lintas modul (di luar rekap absensi) | belum |
| `lms` | `.materials`, `.attachments`, `.assignments`, `.submissions`, `.grading` | – | belum |
| `exams`, `report_cards`, `finance`, `admissions`, `parent_portal`, `notifications` | – | – | belum |

Prasyarat contoh: `academic.*` butuh `students`; `attendance` butuh `students`; `lms` butuh `academic`;
`lms.submissions` butuh `lms.assignments`; `lms.grading` butuh `lms.submissions`; `attendance.scan_qr|scan_rfid`
butuh `attendance`.

**Inti (tidak bisa dimatikan):** profil sekolah, anggota dan undangan, audit log, serta membaca entitlement sendiri.
Tanpa itu admin tidak bisa memulihkan akses sekolahnya.

### 2. Model data
Semua nama mengikuti konvensi `DATABASE_DESIGN.md` (uuidv7, timestamptz, constraint bernama).

| Tabel | Isi | RLS |
|---|---|---|
| `features` | `code` PK, `parent_code`, `name`, `released` | tanpa RLS (global, baca saja bagi aplikasi) |
| `plans` | `code` PK (`basic`/`standard`/`premium`/`legacy`), `name`, `price_per_student_idr`, `min_monthly_idr`, `is_active` | tanpa RLS |
| `plan_features` | `plan_code`, `feature_code` (PK gabungan) | tanpa RLS |
| `school_subscriptions` | `school_id`, `plan_code`, `status` (`trial`/`active`/`ended`), `starts_on`, `ends_on`, `created_by_user_id`; unik parsial: satu langganan non-`ended` per sekolah | RLS: sekolah baca miliknya, platform tulis |
| `school_feature_overrides` | `school_id`, `feature_code`, `effect` (`grant`/`revoke`), `reason`, `created_by_user_id`; unik (`school_id`, `feature_code`) | sama |

Riwayat langganan disimpan (baris lama menjadi `ended`, tidak dihapus). Tagihan/invoice **tidak** termasuk ADR ini:
harga di `plans` hanya referensi sampai model billing diputuskan.

### 3. Aturan penentuan akses efektif
Untuk sekolah S dan fitur F, `efektif(S, F)` benar bila **semua** berikut benar:

1. F berstatus `released` di kode.
2. Dasar: F ada di paket aktif S, kecuali ada override `revoke`; atau ada override `grant`. Override menang atas paket.
3. Induk F dan semua prasyarat F juga efektif (rekursif).
4. Sekolah dapat diakses (`schools.status` trial/active) – penangguhan tetap ditangani `SCHOOL_UNAVAILABLE`.

Setiap fitur yang ditampilkan ke Super Admin membawa **sumber**: `plan`, `override_grant`, `override_revoke`,
`blocked_by_dependency` (dengan daftar prasyaratnya), atau `unreleased`. Itu yang menjawab PRD §5.3 "UI menjelaskan
asal akses".

Himpunan efektif dihitung sekali per request (memo di `TenantContext`) dan di-cache di Redis per sekolah dengan kunci
bertanda versi yang dinaikkan setiap perubahan. Cache hanya optimasi, bukan sumber kebenaran.

### 4. Validasi saat menyimpan (PRD §5.3, §5.5)
- `grant` untuk fitur belum rilis → 422 `FEATURE_UNRELEASED`.
- `grant` yang prasyaratnya tidak akan efektif → 422 `FEATURE_DEPENDENCY_MISSING` + daftar prasyarat.
- `revoke`, ganti paket, atau mencabut `grant` yang membuat fitur turunan tidak lagi efektif → respons **pratinjau
  dampak** (daftar fitur yang ikut mati). Perubahan baru tersimpan bila request mengulang dengan
  `confirm_impact: true`. `dry_run: true` selalu mengembalikan pratinjau tanpa menyimpan.
- Override **tidak pernah dihapus diam-diam** saat paket berganti. Pratinjau menandai override yang jadi
  redundan (sama dengan paket baru) atau bertentangan, dan menyerahkan keputusan ke Super Admin.
- Data tidak pernah dihapus karena fitur nonaktif: hanya akses operasional dicabut. Pembacaan data historis untuk
  keperluan ekspor kepatuhan tidak termasuk dan dibahas terpisah bila diperlukan.

### 5. Penegakan di backend (PRD §5.4)
- Middleware baru `school.feature:<kode>` (`RequireSchoolFeature`, pola sama dengan `school.can`), dideklarasikan per
  rute di `routes/api.php`. Gagal → **403** `FEATURE_NOT_ENTITLED` dengan field `feature`. Diletakkan sebelum
  `school.can` supaya pesan fitur nonaktif tidak bocor ke peran yang memang tak berhak.
- Matriks otorisasi (`RouteMatrix`, T08–T09) diperluas: **setiap** rute `schools/{school}/…` wajib menyebut fitur
  atau ditandai `core`. Rute tanpa keterangan membuat tes gagal, sehingga modul baru tidak bisa lupa gerbang.
- Rute perangkat terminal (di luar prefiks `schools/{school}`): `AuthenticateDevice` memeriksa `attendance.scan_qr`
  atau `attendance.scan_rfid` sesuai jenis kredensial pada scan.
- Job dan ekspor: `TenantAwareJob` mendapat metode `requiredFeature()`; job dibatalkan dengan audit bila fitur
  sudah mati saat dijalankan (bukan hanya saat diantre). `ExportController` memeriksa fitur saat membuat **dan**
  saat mengunduh.
- Unduhan file (LMS nanti) memakai middleware yang sama.
- **Implementasi E2:** rute impor yang tidak membawa jenis di URI (`imports`, `template`, `{import}/…`) digerbang "salah satu"
  dari fitur impor, lalu pengendali memeriksa jenis tepatnya (`ImportKinds::feature`); riwayat impor hanya menampilkan jenis
  yang masih dimiliki. Terminal: grup rute digerbang `attendance`, lalu `scan` memeriksa `attendance.scan_qr`/`scan_rfid` dan
  `config.methods` hanya menyebut metode yang dimiliki. `attendance:close-day` melewati sekolah tanpa `attendance`.
  Perintah retensi (`imports:purge`, `exports:purge`, `audit:scrub-ip`) sengaja tidak digerbang: pembersihan data tetap
  berjalan walau fitur dicabut. Himpunan fitur efektif dihitung sekali per request (memo di `TenantContext`); cache Redis
  ditunda sampai ada ukuran beban.
- Super Admin sebagai Admin Sekolah virtual (D-21) **ikut terkena** entitlement pada rute operasional sekolah.
  Pengelolaan entitlement sendiri ada di `/platform/*` sehingga tidak terkunci.

### 6. API
Platform (Super Admin + 2FA):
- `GET /platform/features` – registri beserta induk, prasyarat, status rilis.
- `GET /platform/plans` – paket dan isi fiturnya (v1 hanya baca; harga/isi paket diubah lewat migration, pengelolaan
  paket lewat UI ditunda).
- `GET /platform/schools/{school}/entitlements` – matriks efektif lengkap dengan sumber tiap fitur.
- `PUT /platform/schools/{school}/subscription` – ganti paket (`plan_code`, `dry_run`, `confirm_impact`, `reason`).
- `PUT /platform/schools/{school}/entitlements/{feature}` – set override `grant`/`revoke`; `DELETE` – hapus override.

Semua perubahan menerima `reason`, `dry_run`, dan `confirm_impact`, dan berjalan dalam satu transaksi yang mengunci baris
sekolah (pratinjau dan penerapan membaca keadaan yang sama). Hasil: `{applied, unchanged, requires_confirmation, impact,
entitlements}`; `impact` = `lost` (efektif sebelumnya, tidak lagi sesudahnya, termasuk turunan yang tertahan), `gained`,
dan `redundant_overrides` (override yang kini sama dengan paket; tetap tersimpan). Konfirmasi diwajibkan bila `lost`
memuat fitur selain fitur yang sedang diubah (ganti paket: bila `lost` tidak kosong). Kode error:
`409 IMPACT_CONFIRMATION_REQUIRED` (body memuat `impact`), `422 FEATURE_UNRELEASED`, `422 FEATURE_DEPENDENCY_MISSING`
(body memuat `missing`), `422` validasi untuk paket tidak dikenal, tidak ditawarkan (`legacy`), atau sama dengan yang berjalan.
Override identik dengan yang sudah ada → `unchanged`, tanpa perubahan dan tanpa audit.

Sekolah:
- `GET /schools/{school}/entitlements` – daftar kode fitur efektif (inti, dipakai UI menyaring menu; hanya UX).

### 7. Audit (PRD §5.6)
Aksi baru lewat `AuditLogger`: `platform.subscription.changed`, `platform.entitlement.override_set`,
`platform.entitlement.override_removed`. Isi: aktor, sebelum/sesudah (termasuk `school_id` sekolah terdampak), `reason`,
dan `impact` (daftar kode fitur yang hilang/bertambah). Tanpa password/token.

**Perubahan dari rancangan awal (E3):** entri dicatat sebagai audit **platform** (`audit_logs.school_id` NULL, seperti
`platform.school.*`), bukan di audit log sekolah. Alasannya: `reason` berisi catatan komersial internal (mis. alasan
diskon/pilot) yang tidak untuk dibaca admin sekolah. Sekolah terdampak tercatat di nilai sebelum/sesudah. Halaman audit
lintas sekolah untuk Super Admin (PRD §5.1) belum ada dan menjadi pekerjaan terpisah.

### 8. Migrasi sekolah yang sudah ada
Paket `legacy` berisi semua fitur yang sudah rilis. Semua sekolah yang ada diberi `legacy` (status `active`), jadi
perilaku produksi/demo tidak berubah saat gerbang dipasang. Sekolah baru wajib memilih paket di form pembuatan.
Pemindahan ke Basic/Standard/Premium dilakukan manual oleh Super Admin dengan pratinjau dampak. Rollback migration
menghapus tabel baru saja; tidak ada kolom lama yang diubah.

### 9. UI
- Detail sekolah (`/platform/[code]`): bagian **Akses Modul & Fitur** berupa pohon checklist. Tiap baris menampilkan
  lencana sumber (Paket / Override aktif / Override dicabut / Menunggu prasyarat / Belum tersedia). Toggle fitur
  belum rilis dinonaktifkan dan dijelaskan. Perubahan yang berdampak membuka dialog konfirmasi berisi pratinjau.
- Menu sekolah (`school-nav.ts`) disaring dengan daftar fitur efektif **dan** izin. Halaman fitur nonaktif yang
  dibuka lewat URL menampilkan keadaan "Fitur tidak termasuk paket sekolah Anda" (dari 403 `FEATURE_NOT_ENTITLED`).

## Tahapan PR
| PR | Isi | Risiko |
|---|---|---|
| E1 | Enum `Feature`, migration + seed tabel, resolver, backfill `legacy`, tes unit resolver dan paritas enum–DB | rendah (belum memengaruhi request) |
| E2 | Middleware `school.feature`, pemasangan di semua rute, perluasan `RouteMatrix`, penegakan di terminal, job, ekspor; tes regresi absensi lengkap | tinggi: diuji paling ketat |
| E3 | API platform (matriks, ganti paket, override, pratinjau dampak) + audit + OpenAPI | sedang |
| E4 | UI Super Admin (checklist) + penyaringan menu sekolah + keadaan "tidak termasuk paket" | rendah |
| E5 | Pembuatan sekolah memilih paket, tanggal uji coba/langganan, ringkasan paket di dasbor | rendah |

## Konsekuensi
- Menambah modul baru berarti menambah kasus di enum `Feature`, mendeklarasikan `school.feature` di rutenya, dan
  mengisi paket. Tes matriks memaksa langkah itu.
- Satu query tambahan (di-cache) per request sekolah.
- Penolakan fitur dan penolakan izin tampil berbeda di API (`FEATURE_NOT_ENTITLED` vs `PERMISSION_DENIED`), UI
  dapat memberi pesan yang tepat.
- Billing, invoice, dan batas penyimpanan sengaja belum dirancang di sini.

## Pertanyaan terbuka (butuh keputusan)
1. **Granularitas fitur** pada tabel §1 sudah sesuai? (Contoh: apakah `attendance.leave` dan `attendance.corrections`
   perlu bisa dijual terpisah, atau cukup satu `attendance`.)
2. **Super Admin virtual** terkena entitlement pada rute operasional (usulan) atau melewatinya?
3. **Paket `legacy`** untuk sekolah yang ada, atau langsung dipetakan ke Standard?
4. **Uji coba:** sekolah berstatus `trial` memakai paket apa, dan apakah ada tanggal berakhir otomatis?
5. **Override berbatas waktu** (mis. buka LMS 30 hari untuk demo) diperlukan sekarang atau nanti?
6. **Pengelolaan paket lewat UI** (ubah harga/isi paket) perlu di E3–E4 atau cukup lewat migration dulu?
