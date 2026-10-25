# TESTING STRATEGY — Sentra Sekolah

**Status:** Draf tahap 1
**Prinsip:** test tidak boleh di-skip atau dinonaktifkan agar build hijau. Test yang tidak stabil (flaky) diperbaiki atau dikarantina dengan issue dan tenggat, dan tidak diam-diam dihapus. Test backend memakai **PostgreSQL sungguhan**, bukan SQLite, karena integritas data bergantung pada fitur PostgreSQL.

---

## 1. Piramida & Alat

| Lapisan | Alat | Cakupan |
|---|---|---|
| Statis | PHPStan/Larastan (level tinggi), Pint, ESLint, `tsc --noEmit` | Setiap commit |
| Unit backend | Pest | Aturan domain murni dengan `Clock` palsu |
| Database | Pest + koneksi PostgreSQL | Constraint, trigger, RLS, diuji langsung via SQL tanpa melewati aplikasi |
| Feature/API | Pest HTTP test | Endpoint + otorisasi + tenant + audit |
| Migrasi | Script CI | Fresh, rollback, snapshot skema |
| Unit/komponen frontend | Vitest + Testing Library | State terminal, form, format waktu lokal |
| E2E | Playwright | Stack penuh (API + DB + web + terminal) dengan seed khusus test |
| Beban | k6 | Endpoint scan & dashboard |
| Keamanan | composer audit, pnpm audit, gitleaks, OWASP ZAP baseline, pentest manual | CI + sebelum rilis |
| Hardware | Protokol manual terdokumentasi | Per model reader/scanner |

Mock HTTP (mis. MSW) hanya dipakai di test komponen frontend dan tidak pernah dibundel ke build aplikasi.

---

## 2. Unit Test (domain)
- **Mesin keadaan scan:** table-driven untuk setiap baris ARCHITECTURE §8.1, dengan titik uji di batas ±1 menit dari `IN_START`, `DEADLINE`, `DEADLINE+GRACE`, `OUT_START`, `OUT_SCHED`, dan `MIN_GAP`.
- **Zona waktu:** transaksi pukul 23.30 WIB vs 00.30 WITA menghasilkan `attendance_date` yang benar; jam server UTC.
- **ScheduleResolver:** prioritas kelas > jenjang > sekolah, tanggal efektif (hari pertama/terakhir), libur, hari tanpa jadwal.
- **DailyAttendanceCalculator:** kombinasi event × izin × koreksi × pulang awal; "belum tercatat" ≠ alpa; idempoten.
- **Kredensial:** format token QR, entropi ≥128 bit, tidak memuat PII; normalisasi UID per profil reader (vektor dari kartu nyata); HMAC berversi.
- **Audit redaksi:** field sensitif tidak pernah lolos allowlist.
- **Policy:** setiap izin × role.

## 3. Database & Migration Testing
- `migrate:fresh` dari nol di setiap pipeline.
- `migrate:rollback --step=N` lalu `migrate` ulang untuk migrasi yang reversibel. Migrasi forward-only diberi penanda dan dikecualikan secara eksplisit.
- **Snapshot skema:** `pg_dump --schema-only` dibandingkan dengan file yang di-commit; perbedaan tak terduga menggagalkan CI.
- **Upgrade N-1 → N** (mulai Tahap 6): restore skema + data rilis sebelumnya, jalankan migrasi baru, verifikasi invariant.
- **Uji constraint langsung** (tanpa model Eloquent), dengan memasukkan data pelanggar dan memastikan DB menolak:
  - FK komposit lintas sekolah
  - accepted ganda `(student, date, event_type)`, idempotency key ganda
  - enrollment/jadwal/tahun ajaran tumpang tindih
  - `UPDATE`/`DELETE` pada `attendance_events` & `audit_logs` dengan role `sentra_app`
  - `method='face'`, assisted tanpa petugas/alasan, alpa tanpa finalisasi
- **RLS:** konteks sekolah A tidak dapat `SELECT/INSERT/UPDATE` baris sekolah B; tanpa konteks, tidak ada baris yang terlihat; konteks tidak bocor ke request berikutnya.

## 4. Authorization & Tenant Isolation Testing
- **Matriks otomatis:** file deklaratif `route → izin yang dibutuhkan`. Test mengiterasi `Route::getRoutes()`:
  - route tanpa entri matriks menggagalkan test, sehingga endpoint baru wajib dideklarasikan;
  - setiap role diuji: yang diizinkan → 2xx, yang tidak → 403; tanpa login → 401.
- **Isolasi tenant:** untuk setiap route dengan parameter ID, gunakan ID milik sekolah B dalam path sekolah A → 404. Endpoint daftar tidak pernah mengembalikan baris sekolah B. Field `school_id` di body diabaikan.
- **Keanggotaan (ADR 0004):**
  - User yang menjadi anggota A dan B dengan peran berbeda: peran A tidak berlaku di B.
  - Bukan anggota → 404; suspended → 403; ended → 404; sekolah suspended → 403.
  - Super Admin tanpa keanggotaan → 404 pada rute sekolah.
  - Konteks DB di-reset antar request.
  - Daftar kasus lengkap: `TAHAP_1_FONDASI.md` §8.
- **Scope wali kelas:** siswa di luar kelasnya → 404 (detail) / tidak muncul (daftar / laporan / ekspor).
- **Device:** token dicabut → 401 `DEVICE_REVOKED`; token device di endpoint dashboard → 401; sesi dashboard di endpoint terminal → 401; QR sekolah A di terminal sekolah B → `UNKNOWN_CREDENTIAL`.
- **Sesi bantuan:** kedaluwarsa, role tanpa izin, sekolah lain → ditolak.

## 5. Integration Testing
| Alur | Verifikasi |
|---|---|
| Terminal → API → PostgreSQL | event + proyeksi + respons + audit dalam satu transaksi; rollback saat audit gagal |
| QR/RFID → pemetaan → event | semua `reason_code` |
| Konkurensi | 20 request paralel (kunci idempotensi berbeda) untuk siswa yang sama → tepat 1 accepted, sisanya duplicate; dijalankan dengan proses paralel nyata |
| Event → harian → laporan | golden dataset (lihat §7) |
| Koreksi → audit | histori adjustment + audit + proyeksi berubah, event tidak berubah |
| Impor → validasi → commit | fixture valid/invalid/berbahaya; commit atomik |
| Ekspor | file di-parse ulang (CSV, XLSX, teks PDF) dan dicocokkan dengan query; escape formula |
| Log | capture log selama scan → tidak ada token/UID mentah/nama siswa |

## 6. End-to-End (Playwright)
Seed khusus E2E (dua sekolah, semua role). Skenario minimum:
1. Super Admin membuat sekolah + admin → admin login → ganti password.
2. Admin: tahun ajaran → kelas → impor siswa (dengan baris gagal) → jadwal → libur.
3. Terbitkan QR → pairing terminal → scan (input keyboard berkecepatan scanner) → state "Berhasil" → scan ulang → "Sudah tercatat".
4. Jaringan diputus (route abort) → terminal menampilkan "Koneksi bermasalah", **tidak pernah** "Berhasil".
5. Petugas: mode bantuan → pencatatan dengan alasan → muncul di audit.
6. Wali kelas: hanya melihat kelasnya; mengajukan koreksi → admin menyetujui → laporan berubah.
7. Ekspor laporan → unduh → isi sesuai.
8. Pengguna sekolah A memanipulasi URL ke ID sekolah B → halaman tidak ditemukan.
9. Navigasi keyboard pada alur petugas; state terminal memiliki teks + ikon (cek aksesibilitas otomatis axe).

Waktu di E2E dikendalikan melalui endpoint clock khusus test yang **hanya terdaftar di environment `testing`** dan dijaga test otomatis agar tidak pernah aktif di produksi.

## 7. Golden Dataset untuk Laporan
Satu bulan kalender fiktif dengan angka yang dihitung manual dan **disetujui pemilik produk** sebelum Tahap 5 selesai: 3 kelas, 30 siswa, 2 hari libur, 1 siswa pindah kelas di tengah bulan, 1 siswa nonaktif, izin multi-hari, koreksi status, pulang awal, terlambat pada batas toleransi. Laporan & ekspor harus mereproduksi angka tersebut persis.

## 8. Uji Beban (k6)
- Skenario jam masuk: S sekolah × ritme 1 scan/2 detik per terminal selama 30 menit + dashboard polling + 1 ekspor besar berjalan.
- Target [PRD 11.1]: p95 scan ≤ 2 detik, dashboard ≤ 3 detik, error rate < 0,1%, tanpa accepted ganda.
- Parameter S (jumlah sekolah serentak) dan jumlah siswa per sekolah ditetapkan bersama pemilik produk berdasarkan target bisnis.

## 9. Uji Hardware & Lapangan
Protokol di `docs/hardware/` untuk setiap model: format output, waktu baca, tap ganda, kartu rusak/tidak terdaftar, layout keyboard, fokus hilang, 50+ kartu beruntun, restart perangkat. Hasil dicatat dengan tanggal, versi firmware, dan OS. Uji lapangan mengikuti PRD §13.4.

## 10. Gerbang CI
| Tahap pipeline | Wajib lulus untuk merge |
|---|---|
| Lint + typecheck + static analysis | ✔ |
| Unit + database + feature + matriks otorisasi | ✔ |
| Migrasi fresh + rollback + snapshot | ✔ |
| Frontend unit | ✔ |
| E2E smoke (skenario 3, 4, 8) | ✔ |
| E2E penuh, load test, ZAP | nightly / sebelum rilis |
| Audit dependensi & secret scan | ✔ (kritis memblokir) |

Target coverage hanya untuk modul `Domain/Attendance`, `Domain/Credentials`, dan `Domain/Tenancy` (≥ 90% baris + semua cabang mesin keadaan). Tidak mengejar angka coverage global.
