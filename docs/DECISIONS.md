# DECISIONS — Register Keputusan Terbuka, Ambiguitas PRD, dan Risiko

**Status:** Draf tahap 1. Setiap butir menunggu keputusan pemilik produk.
Kolom **Blok** menunjukkan tahap paling awal yang terhambat bila keputusan belum diambil.

Setelah diputuskan, butir dipindahkan ke `docs/adr/NNNN-judul.md` (format: konteks, keputusan, konsekuensi).

---

## 0. Keputusan yang sudah diambil (17 Okt 2026)

| ID | Keputusan | Catatan |
|---|---|---|
| D-01 | Laravel 13 + PHP 8.5, Next.js 16 + TypeScript, PostgreSQL 18, monorepo | ADR 0001, 0002 |
| D-02 | Docker Desktop + Docker Compose | ADR 0003 |
| D-05 | Email unik global; identitas terpisah dari keanggotaan & peran; multi-sekolah per akun | ADR 0004 |
| D-09 | Pulang awal hanya dicatat petugas berwenang, dengan alasan + audit | ADR 0005 |
| D-10 | Scan pulang tanpa catatan masuk → `needs_review`, masuk antrean tinjauan | ADR 0005 |
| D-18 | RFID belum dipilih; implementasi lewat abstraksi tanpa mengunci model; hardware belum dibeli | ADR 0006, `hardware/INTEGRASI_PERANGKAT.md` |
| D-19 | Prototipe terminal: laptop/PC; scanner QR USB bila tersedia & kompatibel; uji via keyboard/simulasi | ADR 0006 |
| D-23 | Hosting/lokasi data produksi **tidak dikunci** sampai ada kajian PDP, kebutuhan sekolah (negeri & swasta), dan persyaratan pengadaan | Dev lokal saja |

## 1. Keputusan yang Membutuhkan Input Anda (prioritas)

| ID | Pertanyaan | Rekomendasi | Blok |
|---|---|---|---|
| **D-34** | Pemilihan sekolah lewat path `/api/v1/schools/{school}/…` | Setuju (ADR 0004) | **Tahap 1** |
| **D-37** | Undangan & reset password via email (Mailpit di dev) | Setuju; bila tidak, MVP dibatasi 1 sekolah per user secara fungsional (ADR 0004) | **Tahap 1** |
| D-25 | 2FA TOTP wajib untuk Super Admin | Setuju | Tahap 1 |
| D-38 | Laravel Fortify (headless) untuk login/2FA/reset | Setuju | Tahap 1 |
| D-39 | Cek password bocor via layanan k-anonymity pihak ketiga | Setuju, bisa dimatikan per environment | Tahap 1 |
| D-40 | Klien Redis `predis` | Setuju | Tahap 1 |
| D-41 | `vendor/` & `node_modules/` di named volume Docker | Setuju | Tahap 1 |
| **D-33** | Mekanisme alpa: aturan setelah cutoff → "alpa belum diverifikasi" + pengaman perangkat + verifikasi/koreksi (sesuai arahan Anda, disesuaikan dengan PRD §8.3) | Lihat ADR 0005 §5 | Tahap 5 |

## 2. Ambiguitas / Konflik di PRD

| ID | Temuan | Rekomendasi |
|---|---|---|
| D-07 | PRD meminta CRUD "guru", tetapi role hanya mengenal Wali Kelas, dan `classes.homeroom_teacher_user_id` merujuk user. Tidak semua guru perlu login. | Tabel `teachers` dengan `user_id` opsional; wali kelas merujuk `teachers`. |
| D-08 | "Pulang awal" tercantum sebagai **status** [5.2], padahal siswa bisa sekaligus Terlambat dan Pulang awal. | Atribut terpisah (`early_leave` + status persetujuan). Laporan rekap tetap menghitungnya. |
| D-10 | Kebijakan "pulang tanpa catatan masuk" [4.5]. | **Diputuskan:** `needs_review` → antrean tinjauan petugas (ADR 0005). |
| D-11 | Siswa datang setelah jendela pulang dibuka (mis. datang 12.00 untuk SD yang pulang 12.00). | Diperlakukan sama seperti D-10: `needs_review`. |
| D-11b | Multi-sesi per siswa per hari [5.3, 7.1]. | MVP: 1 sesi/siswa/hari; shift pagi/siang via jadwal per kelas. |
| D-13 | Siswa dengan izin disetujui ternyata scan masuk. | Scan menang (Hadir/Terlambat); konflik masuk antrean review. Dispensasi kegiatan luar tetap Dispensasi [konfirmasi]. Diterapkan: scan menang langsung dan konflik dicatat sebagai audit `school.attendance.leave_overridden` (tanpa antrean review). |
| D-14 | Rumus persentase kehadiran [10]. | (Hadir+Terlambat) / (Hari efektif wajib − Izin − Sakit − Dispensasi yang disetujui), ditampilkan bersama komponen mentahnya. Alternatif per sekolah: penyebut = semua hari efektif. |
| D-15 | Impor: commit sebagian atau semua-atau-tidak sama sekali? | Preview → admin commit **baris valid saja**; baris gagal diunduh sebagai file perbaikan. Satu transaksi untuk seluruh baris valid. |
| D-16 | QR disimpan sebagai hash [7.1] membuat cetak ulang tidak bisa memakai token yang sama. | Hash saja; cetak ulang = terbit ulang (kartu lama otomatis tidak berlaku). |
| D-17 | Boleh lebih dari satu kartu RFID aktif per siswa? | Tidak. Satu QR aktif + satu RFID aktif. |
| D-24 | Jadwal per "kelompok siswa" [5.1], jadwal khusus per tanggal (ujian), libur per jenjang. | MVP: jadwal scope sekolah/jenjang/kelas + libur sekolah. Sisanya backlog pasca-MVP. |
| D-29 | `late_minutes` dihitung dari batas masuk atau dari batas+toleransi? | Dari batas masuk (`check_in_deadline`); dapat diatur per sekolah. |
| — | `TOO_EARLY`: scan sebelum `check_in_start`. | Ditolak dengan pesan jam mulai. |
| — | "Dashboard diperbarui" [4.2] tanpa spesifikasi realtime. | Polling 10 detik di MVP (D-20); websocket (Laravel Reverb) ditunda. |

## 3. Keputusan Teknis (default bila tidak ada keberatan)

| ID | Keputusan | Default |
|---|---|---|
| D-03 | Terminal sebagai app terpisah | Ya, subdomain terpisah, Next.js static export |
| D-04 | Penyimpanan token device | Cookie HttpOnly di origin terminal; token di-hash di DB |
| D-06 | PostgreSQL RLS | Ya, sebagai pertahanan berlapis (menambah kompleksitas pengujian, sudah diperhitungkan) |
| D-20 | Realtime dashboard | Polling |
| D-21 | Akses Super Admin ke data siswa | **Super Admin (lulus 2FA) dapat mengakses sekolah mana pun sebagai Admin Sekolah virtual** (bukan anggota; tidak ada baris keanggotaan). Bertindak penuh seperti Admin Sekolah; setiap request dicatat di audit log sekolah (`platform.access.request`; GET berulang dalam 60 detik dicatat sekali). Sekolah tertangguhkan tetap dapat dibuka, yang terminated tidak. Keputusan pemilik produk; konsekuensi privasi data anak ditanggung lewat audit dan 2FA wajib |
| D-22 | Sekolah `suspended` | Login dashboard & terminal ditolak; data tetap utuh. Konfirmasi kebijakan komersial. |
| D-25 | 2FA & reset password | TOTP wajib Super Admin; reset oleh Admin Sekolah; reset via email setelah penyedia email dipilih |
| D-26 | Retensi (usulan awal, wajib ditinjau hukum) | Event & harian: selama siswa aktif + 5 tahun · Audit log: 5 tahun · IP address: 90 hari · Foto: dihapus 30 hari setelah siswa keluar · Staging impor: 30 hari · File ekspor: 7 hari |
| D-27 | Tabel guardians/notifications | Ditunda (aditif nanti) |
| D-30 | Jumlah device per sekolah di MVP | 1 aktif (batas dapat diubah Super Admin) + penggantian device |
| D-31 | Login petugas di terminal | Email+password → sesi bantuan 10/30 menit; PIN dievaluasi setelah pilot |
| D-32 | Verifikasi wajah | Tidak dibangun di MVP; `method='face'` diblokir constraint; Tahap 7 dengan gerbang kepatuhan |

---

## 4. Risiko Teknis

| # | Risiko | Dampak | Kemungkinan | Mitigasi |
|---|---|---|---|---|
| R1 | Race condition: dua scan bersamaan menghasilkan dua check-in | Tinggi | Sedang | Lock baris harian, partial unique index, idempotency key, uji konkurensi otomatis |
| R2 | Salah tafsir masuk/pulang di batas jendela | Tinggi | Sedang | Mesin keadaan eksplisit + `MIN_GAP`; tabel uji batas waktu (table-driven) |
| R3 | Kebocoran data lintas sekolah | Kritis | Rendah–Sedang | 7 lapis isolasi (ARCHITECTURE §5), matriks uji otomatis untuk setiap route |
| R4 | Ketidakcocokan reader RFID / format UID / layout keyboard | Tinggi | **Tinggi** | Pilih hardware sebelum Tahap 4, profil normalisasi, protokol uji fisik terdokumentasi |
| R5 | Fokus input hilang di kiosk sehingga scan "hilang" tanpa disadari | Sedang | Sedang | Penangkapan input di level dokumen, watchdog fokus, indikator siap |
| R6 | Kartu dipinjamkan/disalin | Sedang | Tinggi | Tampilan identitas/foto untuk pemeriksaan visual, kebijakan sekolah. Diakui sebagai batasan [PRD 8.3]. |
| R7 | Penyalahgunaan mode bantuan oleh petugas | Sedang | Sedang | Sesi individual, alasan wajib, audit, laporan anomali per petugas |
| R8 | Token device dicuri dari terminal | Sedang | Rendah | Cookie HttpOnly, CSP ketat, scope sempit, pencabutan instan, `last_seen_ip` |
| R9 | Antrean gerbang (1 terminal) | Sedang | Tinggi untuk sekolah >800 siswa | Ukur di pilot; multi-terminal pasca-MVP (skema sudah mendukung) |
| R10 | Internet putus, absensi tak tercatat | Sedang | Sedang | Pesan jelas, prosedur kertas → input bantuan/koreksi beralasan; tidak ada antrean offline palsu |
| R11 | Kebocoran data biometrik / pelanggaran UU PDP | Kritis | — (tidak dibangun) | Tidak dibangun di MVP; Tahap 7 dengan DPIA, persetujuan wali, dan kajian hukum |
| R12 | Laporan tidak konsisten dengan transaksi | Tinggi | Sedang | Satu kalkulator proyeksi, data uji emas (golden dataset), uji rekonsiliasi |
| R13 | Konteks RLS bocor antar request (koneksi dipakai ulang) | Kritis | Rendah | Larang koneksi persisten/PgBouncer transaction mode, reset konteks di `terminating`, uji khusus |
| R14 | File impor berbahaya (ukuran, zip bomb, formula) | Sedang | Sedang | Batas ukuran/baris, validasi MIME+ekstensi, parser streaming, escape formula saat ekspor |
| R15 | Jam server tidak tepat sehingga status terlambat keliru | Tinggi | Rendah | NTP, alarm selisih jam, Clock terpusat |
| R16 | Lingkungan lokal belum siap (WSL 2 & Docker belum terpasang; RAM 7,6 GB) | Sedang | Pasti | `DEV_SETUP.md`, batasi memori WSL; konfigurasi Tahap 0 belum terverifikasi runtime |
| R18 | Pengambilalihan akses lintas sekolah lewat akun yang dibuat admin sekolah lain (identitas global) | Kritis | Sedang | Undangan email + verifikasi kepemilikan; admin tidak bisa mengubah password/email (ADR 0004, D-37) |
| R19 | Tab dashboard dengan sekolah berbeda saling tertukar | Tinggi | Sedang | Sekolah di path API & URL, bukan di sesi (D-34) |
| R20 | Alpa berbasis aturan saat perangkat bermasalah (bertentangan dengan PRD §8.3) | Tinggi | Sedang | Status `unverified`, pengaman kesehatan perangkat, verifikasi/koreksi (D-33) |
| R21 | Dependency terbaru belum matang (PHP 8.5 + ekstensi, TypeScript 7) | Sedang | Sedang | Kebijakan versi ≥2 minggu, verifikasi build di CI, fallback versi (ADR 0002) |
| R17 | Kebijakan sekolah berbeda-beda (alpa, izin, pulang awal) | Sedang | Tinggi | `school_settings` bertipe; jangan menanam asumsi di kode |

---

## 5. Prasyarat Biometrik (gerbang Tahap 7)
Modul wajah **tidak boleh dimulai** sebelum seluruh butir berikut terdokumentasi dan disetujui:
1. Kajian hukum atas UU No. 27/2022 (PDP). Data biometrik dan data anak termasuk data pribadi spesifik, jadi perlu dipastikan dasar pemrosesan dan persetujuan orang tua/wali.
2. Penilaian dampak pelindungan data (DPIA).
3. Pemilihan pendekatan (on-device vs layanan pihak ketiga) beserta perjanjian pemrosesan data bila ada vendor.
4. Target dan metode uji FAR/FRR di kondisi pencahayaan sekolah nyata, serta uji anti-spoofing/liveness.
5. Prosedur retensi, penghapusan, dan pencabutan persetujuan.
6. Alternatif nonbiometrik (mode bantuan manual) sudah berjalan dan teruji. Ini terpenuhi di Tahap 4 MVP.
