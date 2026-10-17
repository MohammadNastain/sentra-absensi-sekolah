# PRD — SENTRA ABSENSI SEKOLAH

**Versi:** 1.0  
**Tanggal:** 17 Oktober 2026  
**Status:** Final untuk acuan MVP  
**Produk:** Sentra Absensi Sekolah  
**Platform:** Web dashboard + terminal absensi di gerbang  
**Target:** SD, SMP, SMA/SMK, dan sekolah sederajat  
**Model produk:** SaaS multi-tenant (banyak sekolah dalam satu platform dengan isolasi data)

---

## 1. Ringkasan Produk

Sentra Absensi Sekolah adalah sistem absensi digital untuk mencatat kehadiran siswa menggunakan **QR Code/barcode**, **kartu RFID**, dan **pengenalan wajah sebagai metode alternatif ketika kartu tertinggal**. Seluruh metode terhubung ke backend yang sama sehingga identitas, waktu, aturan keterlambatan, dan laporan dikelola secara konsisten.

Pada MVP, sekolah menggunakan **satu terminal bersama di gerbang**. Admin mengelola data siswa, kelas, jadwal, kredensial kartu, perangkat, status kehadiran, dan laporan melalui dashboard web. Portal orang tua bukan bagian MVP dan direncanakan untuk tahap berikutnya.

### 1.1 Tujuan

- Mempercepat pencatatan kehadiran siswa saat masuk dan pulang sekolah.
- Mengurangi kesalahan rekap manual dan pencatatan ganda.
- Menyediakan laporan kehadiran yang mudah ditinjau dan diekspor.
- Memungkinkan siswa tetap melakukan absensi saat kartu tertinggal melalui prosedur verifikasi wajah yang terkendali.
- Menyediakan jejak audit untuk koreksi dan perubahan data.
- Membangun fondasi yang dapat dikembangkan menjadi SaaS untuk banyak sekolah.

### 1.2 Bukan tujuan MVP

- Portal atau aplikasi khusus orang tua.
- Integrasi dengan sistem informasi akademik eksternal.
- Payroll guru/pegawai.
- Pelacakan lokasi siswa di luar sekolah.
- Absensi berbasis GPS.
- Dukungan offline penuh.
- Keputusan disiplin otomatis berdasarkan data absensi.
- Penggunaan wajah sebagai metode utama atau wajib.

---

## 2. Pengguna dan Hak Akses

### 2.1 Peran pengguna

| Peran | Hak akses utama |
|---|---|
| Super Admin Platform | Mengelola tenant/sekolah, status langganan, dan konfigurasi platform |
| Admin Sekolah | Mengelola data sekolah, siswa, guru, kelas, jadwal, kartu, perangkat, laporan, dan pengguna |
| Kepala Sekolah | Melihat dashboard dan laporan sekolah; tidak mengubah data inti tanpa izin |
| Wali Kelas | Melihat kehadiran siswa di kelas yang ditugaskan dan mengajukan koreksi sesuai kebijakan |
| Petugas Piket | Memantau transaksi harian, menangani kartu tertinggal, dan meninjau kasus yang gagal |
| Operator Terminal | Mengoperasikan terminal dan melihat status transaksi; tidak boleh mengakses seluruh data administratif |
| Siswa | Tidak membutuhkan akun pada MVP; melakukan absensi melalui terminal |

### 2.2 Prinsip otorisasi

- Setiap akun selain Super Admin terikat pada sekolah/tenant.
- Pengguna hanya boleh melihat data sesuai peran dan ruang lingkup penugasannya.
- Hak akses ditegakkan di backend, bukan hanya dengan menyembunyikan menu di frontend.
- Tindakan sensitif seperti mengubah status kehadiran, memblokir kartu, atau mengubah jadwal harus dicatat di audit log.
- Akun bersama untuk beberapa petugas tidak disarankan; setiap petugas menggunakan akun individual.

---

## 3. Ruang Lingkup Fitur

### 3.1 Fitur MVP — wajib

1. Login dan logout.
2. Multi-tenant sekolah dengan isolasi data.
3. Dashboard ringkasan kehadiran harian.
4. CRUD data siswa, guru, kelas, tahun ajaran, dan rombongan belajar.
5. Impor data siswa melalui CSV/XLSX dengan validasi dan laporan baris yang gagal.
6. Pengelolaan jadwal masuk/pulang, hari efektif, hari libur, dan toleransi keterlambatan.
7. Pembuatan QR Code unik untuk setiap siswa.
8. Registrasi, pemetaan, pemblokiran, dan penggantian kartu RFID.
9. Terminal absensi berbasis web pada satu perangkat di gerbang.
10. Pencatatan masuk dan pulang dengan QR Code dan RFID.
11. Penanganan kartu tertinggal melalui alur verifikasi alternatif oleh petugas, dengan pengenalan wajah sebagai pilihan jika telah diaktifkan dan disetujui secara sah.
12. Pencegahan scan ganda dan validasi status kartu/siswa.
13. Daftar transaksi terbaru dan status transaksi.
14. Penentuan status hadir/terlambat berdasarkan jadwal sekolah.
15. Status izin, sakit, dispensasi, alpa, dan pulang awal melalui pengelolaan yang dapat diaudit.
16. Koreksi manual dengan alasan wajib dan catatan audit.
17. Laporan harian, bulanan, per kelas, per siswa, dan keterlambatan.
18. Ekspor CSV/XLSX dan PDF.
19. Manajemen pengguna dan hak akses.
20. Audit log untuk tindakan sensitif.
21. Pengaturan perangkat terminal dan indikator koneksi.
22. Validasi server-side atas semua transaksi absensi.

### 3.2 Tahap berikutnya

- Portal orang tua dan notifikasi kehadiran.
- Integrasi WhatsApp melalui penyedia resmi dan sesuai kebijakan opt-in.
- Aplikasi mobile siswa/orang tua jika kebutuhan telah tervalidasi.
- Dukungan beberapa terminal per sekolah.
- Sinkronisasi offline dengan antrean transaksi dan rekonsiliasi.
- Integrasi dengan SIAKAD atau sistem sekolah lain.
- Paket berlangganan, pembayaran, dan pengelolaan tenant mandiri.
- Analitik lanjutan dan tren kehadiran.

---

## 4. Alur Operasional

### 4.1 Alur setup sekolah

1. Super Admin membuat tenant sekolah.
2. Admin Sekolah melengkapi profil sekolah, zona waktu, jenjang, tahun ajaran, dan hari efektif.
3. Admin membuat kelas dan jadwal masuk/pulang.
4. Admin mengimpor data siswa dan memeriksa laporan validasi.
5. Sistem membuat identitas internal siswa dan QR Code unik.
6. Admin mendaftarkan kartu RFID bila digunakan.
7. Admin mendaftarkan perangkat terminal dan menguji koneksi.
8. Admin mengatur metode absensi yang diaktifkan serta alur bantuan untuk kartu tertinggal.
9. Petugas menjalankan uji coba sebelum operasional harian.

### 4.2 Alur absensi QR Code

1. Siswa menunjukkan kartu/QR Code pada scanner atau kamera terminal.
2. Terminal mengirim token QR dan identitas perangkat ke backend melalui HTTPS.
3. Backend memvalidasi token, sekolah, siswa aktif, kredensial aktif, jadwal, dan sesi absensi.
4. Backend memeriksa apakah kejadian yang sama sudah tercatat.
5. Backend menyimpan transaksi dan menentukan status berdasarkan aturan sekolah.
6. Terminal menampilkan nama, foto (jika diizinkan), kelas, waktu, dan hasil transaksi.
7. Dashboard diperbarui.

QR Code sebaiknya berisi token acak atau identifier non-sensitif, bukan NISN, tanggal lahir, atau data pribadi yang dapat dibaca langsung.

### 4.3 Alur absensi RFID

1. Siswa menempelkan kartu ke reader.
2. Reader mengirim ID kartu ke terminal.
3. Terminal meneruskan ID kartu dan identitas perangkat ke backend.
4. Backend memetakan ID kartu ke kredensial aktif dan siswa yang sesuai.
5. Backend memvalidasi jadwal dan duplikasi, lalu menyimpan transaksi.
6. Terminal menampilkan hasil.

Perangkat RFID harus diuji kompatibilitasnya. RFID 125 kHz dan NFC/RFID 13,56 MHz tidak otomatis kompatibel. Untuk MVP, pilih satu tipe reader/kartu yang jelas dan terdokumentasi.

### 4.4 Alur kartu tertinggal

1. Siswa melapor kepada petugas piket.
2. Petugas membuka mode bantuan pada terminal menggunakan akun petugas.
3. Identitas siswa diverifikasi dengan prosedur sekolah, misalnya pencarian nama/nomor siswa dan pemeriksaan visual oleh petugas.
4. Jika pengenalan wajah telah diaktifkan dan memiliki dasar pemrosesan yang sah, siswa dapat memilih verifikasi wajah sebagai alternatif.
5. Jika verifikasi berhasil, backend mencatat absensi dengan `method = face` atau `method = assisted_manual`, sesuai metode yang benar-benar digunakan.
6. Jika verifikasi gagal, sistem tidak membuat absensi otomatis; petugas mengikuti prosedur pemeriksaan manual.
7. Semua penggunaan mode bantuan dicatat dengan identitas petugas dan alasan.

Jangan mengizinkan siapa pun mengetik nama siswa lalu langsung membuat absensi tanpa autentikasi petugas dan jejak audit. Sediakan alternatif nonbiometrik yang wajar bagi siswa yang tidak menggunakan verifikasi wajah.

### 4.5 Alur pulang

1. Siswa melakukan scan saat meninggalkan sekolah.
2. Backend mengenali kejadian sebagai `check_out` berdasarkan jadwal/sesi dan status sebelumnya.
3. Backend memvalidasi bahwa siswa memiliki catatan masuk atau menerapkan kebijakan pengecualian yang telah ditentukan.
4. Waktu pulang disimpan dari server.
5. Jika pulang lebih awal, sistem menandai status sesuai kebijakan dan kebutuhan persetujuan.
6. Terminal menampilkan hasil transaksi.

### 4.6 Scan ganda

- Scan berulang dalam jendela waktu pendek tidak membuat catatan baru.
- Sistem menampilkan pesan seperti “Absensi masuk sudah tercatat pukul 07.08”.
- Backend menggunakan idempotency key atau aturan unik untuk mencegah transaksi ganda.
- Scan pulang tidak boleh ditafsirkan sebagai scan masuk hanya karena siswa memindai ulang kartu.
- Kasus yang ambigu ditampilkan kepada petugas untuk ditinjau.

---

## 5. Aturan Bisnis Absensi

### 5.1 Aturan waktu

- Semua transaksi memiliki `occurred_at_server` yang ditentukan backend.
- Zona waktu sekolah disimpan dalam konfigurasi tenant; default Indonesia perlu dipilih sesuai lokasi sekolah (`Asia/Jakarta`, `Asia/Makassar`, atau `Asia/Jayapura`).
- Waktu pada perangkat tidak menjadi sumber waktu otoritatif.
- Jadwal dapat berbeda berdasarkan jenjang, hari, kelas, atau kelompok siswa.
- Perubahan jadwal hanya berlaku sesuai tanggal efektif dan dicatat di audit log.

### 5.2 Status kehadiran

| Status | Definisi |
|---|---|
| Hadir | Kehadiran tercatat dalam rentang waktu sesuai aturan sekolah |
| Terlambat | Check-in setelah batas toleransi |
| Izin | Ketidakhadiran yang disetujui |
| Sakit | Ketidakhadiran karena sakit yang dicatat sesuai kebijakan |
| Dispensasi | Ketidakhadiran/kegiatan khusus yang disetujui |
| Alpa | Ketidakhadiran tanpa keterangan setelah pemeriksaan dan aturan sekolah diterapkan |
| Pulang awal | Check-out sebelum jadwal pulang yang ditentukan |

### 5.3 Prinsip penting

- “Belum tercatat” bukan status yang sama dengan “alpa”.
- Sistem tidak otomatis menjatuhkan sanksi kepada siswa.
- Status akhir harian dapat berubah karena izin yang disetujui atau koreksi resmi; perubahan harus memiliki alasan dan audit trail.
- Sekolah menentukan kebijakan keterlambatan, dispensasi, dan pulang awal.
- Absensi masuk dan pulang disimpan sebagai kejadian terpisah.
- Hari libur dan hari tanpa jadwal tidak menghasilkan status alpa otomatis.
- Jika siswa memiliki beberapa jadwal/shift, backend harus menentukan sesi yang tepat sebelum memproses transaksi.

---

## 6. Arsitektur Teknis

### 6.1 Rekomendasi stack

| Lapisan | Teknologi yang disarankan | Catatan |
|---|---|---|
| Frontend dashboard | Next.js + TypeScript | Admin, kepala sekolah, guru, dan petugas |
| Terminal absensi | Mode kiosk berbasis web | Dijalankan pada komputer/tablet di gerbang |
| Backend API | Laravel | Validasi, autentikasi, aturan bisnis, laporan |
| Database | PostgreSQL | Relasi, constraint, transaksi, dan isolasi tenant |
| Penyimpanan file | Object storage privat | Foto siswa dan berkas terkait jika diperlukan |
| QR Code | Library QR standar | Token acak/non-sensitif |
| RFID | Reader USB atau reader jaringan teruji | Pilih model yang kompatibel |
| Pengenalan wajah | Modul terpisah dan terbatas | Tidak wajib diaktifkan pada MVP awal |
| Hosting | VPS/cloud terkelola | HTTPS, backup, monitoring, dan pembaruan |
| Laporan | CSV/XLSX/PDF | Pemrosesan server-side dengan kontrol akses |

### 6.2 Diagram konseptual

```text
[QR Scanner / RFID Reader / Kamera Terminal]
                    |
                    v
         [Terminal Kiosk Web]
                    |
               HTTPS / API
                    |
                    v
          [Backend Laravel API]
          |         |          |
          v         v          v
     [Auth/RBAC] [Attendance] [Audit Log]
          |         |          |
          +---------+----------+
                    |
                    v
             [PostgreSQL]
                    |
          +---------+----------+
          |                    |
          v                    v
 [Dashboard Next.js]    [Laporan/Export]
```

### 6.3 Prinsip arsitektur

- Backend adalah sumber kebenaran untuk identitas, waktu, jadwal, dan status absensi.
- Terminal tidak menyimpan kredensial admin yang memiliki hak istimewa.
- Semua endpoint memeriksa autentikasi, otorisasi, dan `school_id`.
- Transaksi absensi disimpan secara atomik.
- Gunakan constraint database untuk membantu mencegah duplikasi.
- Pengenalan wajah diisolasi sebagai modul yang dapat dinonaktifkan tanpa merusak alur QR/RFID.
- Jangan menyimpan gambar wajah mentah jika tidak diperlukan; utamakan desain biometrik yang meminimalkan data dan tetapkan masa retensi.
- Semua secret dan kredensial berada di environment/server secret manager, bukan di repositori.

---

## 7. Desain Database

Nama tabel berikut adalah rancangan logis. Nama kolom dapat disesuaikan selama relasi, batasan, dan kebutuhan audit dipertahankan.

### 7.1 Tabel inti

#### `schools`
- `id` UUID, primary key
- `name`
- `code`
- `education_levels` atau konfigurasi jenjang
- `timezone`
- `address` (opsional)
- `status`
- `created_at`, `updated_at`

#### `users`
- `id` UUID
- `school_id` nullable hanya untuk Super Admin platform
- `name`
- `email` atau username
- `password_hash` dikelola framework
- `status`
- `last_login_at`
- `created_at`, `updated_at`

#### `roles` dan `user_roles`
- Daftar role dan relasi pengguna-role.
- Jika satu pengguna bisa memiliki beberapa peran, gunakan relasi many-to-many.
- Pembatasan ruang lingkup kelas dapat disimpan pada tabel assignment tersendiri.

#### `students`
- `id` UUID
- `school_id`
- `student_number`
- `nisn` nullable bila diperlukan dan sah
- `full_name`
- `photo_object_key` nullable
- `date_of_birth` hanya jika benar-benar diperlukan
- `status` (active, inactive, graduated, transferred)
- `created_at`, `updated_at`

Unique constraint: `(school_id, student_number)`.

#### `guardians` dan `student_guardians`
Disiapkan untuk tahap portal orang tua. Data kontak tidak harus diaktifkan pada MVP jika belum dibutuhkan.

#### `academic_years`
- `id`, `school_id`
- `name`
- `start_date`, `end_date`
- `is_active`

#### `classes`
- `id`, `school_id`, `academic_year_id`
- `name`, `grade_level`
- `homeroom_teacher_user_id` nullable
- `created_at`, `updated_at`

#### `class_enrollments`
- `id`, `school_id`, `student_id`, `class_id`
- `start_date`, `end_date` nullable
- `status`

Gunakan riwayat penempatan agar perpindahan kelas tidak menghapus sejarah absensi.

#### `schedules`
- `id`, `school_id`, `class_id` nullable untuk jadwal umum
- `day_of_week`
- `check_in_start`
- `check_in_deadline`
- `late_grace_minutes`
- `check_out_start`
- `scheduled_check_out`
- `effective_from`, `effective_until`
- `is_active`

#### `student_credentials`
- `id`, `school_id`, `student_id`
- `credential_type` (`qr`, `rfid`)
- `credential_value_hash` atau referensi aman
- `status` (`active`, `blocked`, `revoked`)
- `issued_at`, `revoked_at`
- `created_by_user_id`

QR menggunakan token acak. Jika memungkinkan, simpan hash token untuk pencocokan, bukan token mentah. ID RFID yang dapat dibaca reader perlu diperlakukan sebagai identifier, bukan bukti autentikasi yang kuat dengan sendirinya.

#### `devices`
- `id`, `school_id`
- `name`
- `device_type`
- `device_identifier`
- `status`
- `last_seen_at`
- `created_at`, `updated_at`

#### `attendance_sessions`
- `id`, `school_id`
- `session_date`
- `schedule_id`
- `session_type` (`school_day` atau konfigurasi lain)
- `status`

#### `attendance_events`
- `id` UUID
- `school_id`, `student_id`
- `attendance_session_id`
- `event_type` (`check_in`, `check_out`)
- `method` (`qr`, `rfid`, `face`, `assisted_manual`)
- `occurred_at_server`
- `device_id`
- `result` (`accepted`, `duplicate`, `rejected`, `needs_review`)
- `rejection_reason` nullable
- `idempotency_key`
- `created_by_user_id` nullable
- `metadata` JSONB terbatas dan non-sensitif

Catatan penting: transaksi yang ditolak atau duplikat dapat dicatat untuk audit/diagnostik, tetapi tidak boleh dihitung sebagai kehadiran yang sah.

#### `daily_attendance`
- `id`, `school_id`, `student_id`
- `attendance_date`
- `attendance_status`
- `check_in_event_id` nullable
- `check_out_event_id` nullable
- `late_minutes`
- `source` (`automatic`, `manual_adjustment`)
- `approved_by_user_id` nullable
- `adjustment_reason` nullable
- `created_at`, `updated_at`

Unique constraint: `(school_id, student_id, attendance_date)` untuk satu ringkasan harian per siswa, kecuali kebutuhan multi-sesi telah didefinisikan secara eksplisit. Jika siswa bisa memiliki beberapa sesi terpisah per hari, constraint harus mencakup `attendance_session_id`.

#### `leave_requests`
- `id`, `school_id`, `student_id`
- `leave_type`
- `start_date`, `end_date`
- `reason`
- `attachment_object_key` nullable
- `status` (`pending`, `approved`, `rejected`)
- `submitted_by_user_id`
- `reviewed_by_user_id` nullable
- `reviewed_at` nullable

#### `notifications`
Disiapkan untuk notifikasi internal dan portal orang tua tahap berikutnya:
- `id`, `school_id`, `recipient_user_id` nullable
- `notification_type`
- `payload` non-sensitif
- `status`
- `sent_at` nullable
- `created_at`

#### `audit_logs`
- `id`, `school_id` nullable untuk aktivitas platform
- `actor_user_id`
- `action`
- `entity_type`, `entity_id`
- `reason` nullable
- `before_value` dan `after_value` yang telah disaring dari data sensitif
- `ip_address` sesuai kebijakan retensi
- `created_at`

#### `biometric_profiles` — opsional/ditunda
- `id`, `school_id`, `student_id`
- `provider_reference` atau referensi template terenkripsi
- `status`
- `enrollment_consent_or_legal_basis_reference`
- `created_at`, `updated_at`
- `retention_expires_at`

Jangan menyimpan template biometrik dalam bentuk terbuka. Sebelum fitur ini diluncurkan, definisikan proses pendaftaran, dasar pemrosesan yang sah, retensi, penghapusan, pengamanan, evaluasi akurasi, dan alternatif nonbiometrik.

### 7.2 Aturan integritas data

- Tabel data sekolah menyertakan `school_id` dan relasi yang tervalidasi.
- Terapkan foreign key dan unique constraint yang relevan.
- Transaksi check-in/check-out harus konsisten dalam satu database transaction.
- Gunakan idempotency key dan constraint untuk mencegah retry jaringan membuat transaksi ganda.
- Waktu transaksi menggunakan timestamp server yang konsisten.
- Jangan menghapus transaksi historis untuk memperbaiki kesalahan; buat koreksi yang dapat diaudit.
- Tentukan kebijakan retensi untuk transaksi, audit log, foto, dan biometrik sebelum produksi.

---

## 8. Keamanan, Privasi, dan Perlindungan Anak

### 8.1 Kontrol keamanan minimum

- HTTPS wajib untuk seluruh komunikasi.
- Password di-hash menggunakan mekanisme yang direkomendasikan framework.
- Validasi input di frontend dan backend.
- Rate limiting pada login dan endpoint sensitif.
- Cookie/session/token ditangani sesuai praktik aman dan tidak disimpan sembarangan.
- RBAC dan pemeriksaan tenant pada setiap permintaan.
- Audit log untuk koreksi absensi, perubahan jadwal, pengelolaan kredensial, dan tindakan admin.
- Backup terjadwal, pemantauan kegagalan, dan uji pemulihan.
- Pembatasan ukuran dan tipe file saat impor.
- Foto siswa disimpan privat dan hanya dapat diakses oleh pengguna yang berwenang.
- Hindari menyimpan data pribadi dalam QR Code atau log teknis.
- Kredensial perangkat unik, dapat dicabut, dan tidak dibagikan antarperangkat.
- Pembaruan dependensi dan pemeriksaan kerentanan sebelum rilis.

### 8.2 Perlindungan biometrik

Pengenalan wajah adalah alternatif, bukan kewajiban.

Sebelum diaktifkan:
1. Tentukan dan dokumentasikan dasar pemrosesan data yang sah serta kewajiban persetujuan/perwakilan orang tua sesuai hukum yang berlaku.
2. Berikan penjelasan yang mudah dipahami mengenai tujuan, jenis data, akses, masa simpan, dan cara meminta penghapusan.
3. Sediakan alternatif nonbiometrik yang efektif dan tidak diskriminatif.
4. Evaluasi false acceptance dan false rejection pada kondisi nyata.
5. Uji ketahanan terhadap foto/video palsu (liveness/anti-spoofing) jika digunakan.
6. Batasi akses ke profil biometrik dan enkripsi data sensitif.
7. Jangan memakai foto atau template biometrik untuk tujuan lain.
8. Tetapkan prosedur ketika verifikasi gagal, termasuk penanganan manual oleh petugas.
9. Tetapkan masa retensi dan prosedur penghapusan ketika siswa keluar atau dasar pemrosesan berakhir.
10. Lakukan penilaian dampak privasi dan tinjau kepatuhan pada peraturan Indonesia yang relevan, termasuk UU Perlindungan Data Pribadi.

### 8.3 Batasan anti-kecurangan

- Kartu RFID/QR yang statis dapat dipinjamkan atau disalin; jangan menganggap kartu saja sebagai bukti absolut bahwa pemilik hadir.
- Terminal harus menampilkan identitas untuk pemeriksaan visual sesuai kebutuhan dan kebijakan privasi sekolah.
- Fitur wajah tidak boleh diaktifkan sebelum kontrol privasi dan keamanan siap.
- Petugas tidak boleh dapat mengubah waktu transaksi tanpa jejak audit.
- Ketidakhadiran tidak boleh ditetapkan hanya berdasarkan kegagalan perangkat atau tidak adanya scan.

---

## 9. UI/UX dan Halaman

### 9.1 Dashboard admin

- Ringkasan jumlah siswa aktif.
- Jumlah sudah hadir, terlambat, izin/sakit yang disetujui, dan belum tercatat.
- Grafik tren kehadiran.
- Daftar transaksi terbaru.
- Peringatan perangkat tidak aktif atau transaksi gagal.
- Filter tanggal, kelas, dan jenjang.

Label “belum tercatat” harus dipisahkan dari “alpa”.

### 9.2 Halaman data

- Siswa: daftar, pencarian, filter, tambah/edit, impor, ekspor, detail.
- Kelas: tahun ajaran, jenjang, wali kelas, anggota.
- Jadwal: jam masuk/pulang, toleransi, hari aktif, tanggal efektif.
- Kredensial: QR, RFID, status kartu, riwayat pemblokiran/penggantian.
- Pengguna dan role.
- Perangkat terminal.
- Izin/sakit.
- Laporan.
- Audit log.
- Pengaturan sekolah.

### 9.3 Layar terminal gerbang

Layar terminal dibuat sederhana, besar, kontras, dan mudah dibaca dari jarak dekat.

Keadaan UI minimum:
- Siap memindai.
- Sedang memproses.
- Absensi berhasil.
- Sudah tercatat sebelumnya.
- Kartu/siswa tidak dikenal.
- Kredensial diblokir.
- Jadwal tidak aktif.
- Verifikasi diperlukan.
- Koneksi backend bermasalah.

Informasi yang tampil hanya yang diperlukan, misalnya nama, kelas, foto jika dibutuhkan, waktu, dan status. Jangan menampilkan data sensitif atau alasan izin secara terbuka di gerbang.

### 9.4 Aksesibilitas

- Bahasa antarmuka utama: Bahasa Indonesia.
- Layout responsif untuk desktop, tablet, dan ponsel.
- Ukuran teks dan tombol cukup besar pada terminal.
- Status tidak hanya dibedakan dengan warna; gunakan teks dan ikon.
- Pesan kesalahan menjelaskan tindakan selanjutnya.
- Dukungan keyboard untuk alur petugas.

---

## 10. Laporan dan Perhitungan

MVP menyediakan:
- Laporan kehadiran harian.
- Laporan per siswa.
- Laporan per kelas dan jenjang.
- Rekap keterlambatan.
- Rekap izin, sakit, dispensasi, alpa, dan pulang awal.
- Riwayat check-in/check-out.
- Laporan koreksi manual.
- Laporan transaksi ditolak/duplikat untuk petugas berwenang.

Laporan dapat difilter berdasarkan rentang tanggal, kelas, siswa, dan status, serta diekspor ke CSV/XLSX dan PDF.

Persentase kehadiran harus memakai jumlah hari efektif atau sesi yang memang wajib diikuti siswa. Definisi pembilang dan penyebut harus konsisten dan terdokumentasi. Hari libur, siswa yang belum aktif, perpindahan kelas, dan izin yang disetujui harus diperlakukan sesuai kebijakan sekolah.

---

## 11. Non-Functional Requirements

### 11.1 Performa
- Target awal: 95% transaksi absensi normal mendapatkan respons dalam ≤ 2 detik pada kondisi jaringan dan server yang memenuhi spesifikasi.
- Dashboard daftar standar merespons dalam ≤ 3 detik pada dataset target.
- Uji beban ditetapkan berdasarkan perkiraan jumlah siswa, kecepatan scan puncak, dan jumlah sekolah.
- Proses ekspor laporan besar dilakukan secara terkontrol agar tidak menghambat transaksi terminal.

### 11.2 Ketersediaan
- Sistem MVP memerlukan internet aktif karena belum mendukung mode offline penuh.
- Jika koneksi gagal, terminal menampilkan pesan jelas dan tidak berpura-pura transaksi berhasil.
- Tentukan target uptime dan prosedur pemulihan sebelum kontrak produksi.
- Monitoring memeriksa API, database, backup, dan status terminal.

### 11.3 Skalabilitas
- `school_id` menjadi dasar isolasi tenant.
- Query laporan menggunakan indeks yang sesuai.
- Indeks awal: `(school_id, attendance_date)`, `(school_id, student_id, attendance_date)`, `(school_id, occurred_at_server)`, dan indeks pencarian siswa sesuai pola penggunaan.
- Pertimbangkan partisi tabel event jika volume transaksi tumbuh signifikan.

### 11.4 Kompatibilitas
- Browser modern yang didukung untuk dashboard.
- Perangkat terminal ditetapkan dan diuji secara eksplisit.
- Reader QR/RFID tidak dianggap kompatibel hanya karena menggunakan konektor USB; lakukan uji perangkat.
- Kamera dan modul wajah harus diuji pada kondisi pencahayaan sekolah yang nyata.

---

## 12. Acceptance Criteria

Fitur dianggap memenuhi MVP apabila skenario berikut lulus pengujian.

### A. Login dan hak akses
- [ ] Pengguna yang valid dapat login dan logout.
- [ ] Pengguna nonaktif tidak dapat login.
- [ ] Guru hanya dapat mengakses kelas sesuai penugasan.
- [ ] Pengguna sekolah A tidak dapat membaca atau mengubah data sekolah B, termasuk melalui manipulasi URL/API.
- [ ] Tindakan admin sensitif masuk audit log.

### B. Data siswa
- [ ] Admin dapat membuat, mengubah, menonaktifkan, mencari, dan memfilter siswa.
- [ ] Nomor siswa tidak dapat duplikat dalam satu sekolah.
- [ ] Impor file memberikan ringkasan baris berhasil dan gagal beserta alasannya.
- [ ] Menonaktifkan siswa tidak menghapus riwayat kehadiran sebelumnya.

### C. QR Code dan RFID
- [ ] QR valid untuk siswa aktif menghasilkan transaksi yang sesuai.
- [ ] QR tidak dikenal, kartu diblokir, atau siswa nonaktif ditolak dengan pesan yang jelas.
- [ ] RFID yang terdaftar dipetakan ke siswa yang benar.
- [ ] Kredensial dapat diblokir atau diganti tanpa menghapus riwayat.
- [ ] ID QR tidak mengekspos data pribadi siswa.
- [ ] Scan ulang tidak membuat kehadiran sah ganda.

### D. Waktu dan status
- [ ] Waktu transaksi berasal dari server.
- [ ] Status hadir/terlambat mengikuti jadwal dan toleransi sekolah.
- [ ] Jadwal libur tidak menghasilkan alpa otomatis.
- [ ] “Belum tercatat” tidak disamakan dengan “alpa”.
- [ ] Check-in dan check-out disimpan terpisah.
- [ ] Koreksi manual memerlukan alasan dan menyimpan siapa serta kapan perubahan dilakukan.

### E. Kartu tertinggal dan wajah
- [ ] Siswa yang tidak membawa kartu dapat diarahkan ke prosedur bantuan.
- [ ] Hanya petugas berwenang yang dapat membuka mode bantuan.
- [ ] Penggunaan verifikasi wajah hanya tersedia jika fitur telah diaktifkan dan persyaratan privasi terpenuhi.
- [ ] Kegagalan verifikasi tidak membuat absensi otomatis.
- [ ] Alternatif nonbiometrik tersedia dan dapat diaudit.

### F. Dashboard dan laporan
- [ ] Ringkasan dashboard konsisten dengan data transaksi yang sah.
- [ ] Filter tanggal dan kelas bekerja sesuai hasil yang diharapkan.
- [ ] Laporan CSV/XLSX/PDF dapat dibuka dan nilainya sesuai data.
- [ ] Laporan membedakan status akhir harian dan riwayat scan mentah.
- [ ] Pengguna tidak berwenang tidak dapat mengekspor data yang bukan haknya.

### G. Operasional dan keamanan
- [ ] Terminal menampilkan kegagalan koneksi dan tidak mengklaim transaksi berhasil.
- [ ] Semua endpoint sensitif memvalidasi autentikasi, role, dan tenant.
- [ ] Backup berhasil dibuat dan pemulihan telah diuji.
- [ ] File impor divalidasi untuk tipe, ukuran, dan format.
- [ ] Uji beban sesuai target performa selesai sebelum rilis.
- [ ] Tidak ada kerentanan kritis terbuka saat rilis produksi.

---

## 13. Strategi Pengujian

### 13.1 Unit test
- Aturan keterlambatan dan jadwal.
- Validasi kredensial.
- Pencegahan duplikasi.
- Perhitungan ringkasan harian.
- Otorisasi role dan tenant.

### 13.2 Integration test
- Terminal → API → PostgreSQL.
- QR/RFID → pemetaan siswa → pencatatan event.
- Event → ringkasan harian → laporan.
- Koreksi manual → audit log.
- Impor data → validasi → hasil impor.

### 13.3 Security test
- Percobaan akses lintas sekolah.
- IDOR/manipulasi identifier.
- Brute-force login dan rate limit.
- SQL injection, XSS, CSRF sesuai arsitektur.
- Pengujian hak akses ekspor.
- Kebocoran token atau data sensitif pada log.
- Penanganan kredensial perangkat yang dicabut.

### 13.4 Uji lapangan
- Simulasikan jam masuk ketika banyak siswa melakukan scan berurutan.
- Uji kondisi pencahayaan dan posisi terminal.
- Uji kartu rusak, kartu tertinggal, dan siswa tidak dikenal.
- Uji internet terputus.
- Uji perubahan jadwal dan hari libur.
- Uji prosedur manual petugas ketika perangkat tidak dapat digunakan.

---

## 14. Urutan Implementasi

### Sprint 0 — Fondasi
- Tetapkan spesifikasi perangkat terminal dan reader.
- Siapkan repository, environment, CI, dan standar coding.
- Rancang ERD dan kontrak API.
- Siapkan migrasi database dan seed data demo.
- Implementasikan autentikasi, tenant, RBAC, dan audit log dasar.

**Definition of done:** aplikasi berjalan pada environment pengembangan; migrasi dapat diulang; pengujian isolasi tenant dasar lulus.

### Sprint 1 — Data sekolah
- CRUD sekolah, tahun ajaran, kelas, siswa, dan pengguna.
- Impor CSV/XLSX.
- Penugasan wali kelas.
- Pengaturan jadwal dan hari libur.

**Definition of done:** admin dapat menyiapkan data sekolah tanpa memasukkan setiap siswa satu per satu.

### Sprint 2 — Terminal QR
- Layar kiosk.
- Pembuatan dan pencetakan QR.
- Endpoint validasi dan pencatatan event.
- Penentuan check-in/check-out.
- Pencegahan duplikasi dan tampilan hasil transaksi.

**Definition of done:** alur QR berfungsi end-to-end dengan transaksi yang dapat diaudit.

### Sprint 3 — RFID dan operasi harian
- Integrasi reader RFID terpilih.
- Pendaftaran, penggantian, dan pemblokiran kartu.
- Daftar transaksi terbaru.
- Penanganan kesalahan perangkat.
- Uji beban jam masuk.

**Definition of done:** reader yang ditetapkan berhasil diuji pada perangkat target.

### Sprint 4 — Rekap dan koreksi
- Ringkasan harian.
- Status keterlambatan.
- Pengelolaan izin/sakit.
- Koreksi manual dengan alasan.
- Laporan per siswa/kelas dan ekspor.

**Definition of done:** laporan cocok dengan data uji yang telah ditetapkan.

### Sprint 5 — Hardening dan pilot
- Security review.
- Backup dan uji pemulihan.
- Pengujian lintas tenant.
- Pengujian lapangan di satu sekolah.
- Dokumentasi admin dan petugas.
- Perbaikan masalah pilot.

**Definition of done:** seluruh acceptance criteria kritis lulus dan sekolah pilot menyetujui prosedur operasional.

### Setelah MVP
- Evaluasi kebutuhan pengenalan wajah dan perlindungan biometrik.
- Portal orang tua dan notifikasi.
- Mode offline jika dibutuhkan.
- Multi-terminal, integrasi akademik, dan billing SaaS.

Urutan ini adalah urutan dependensi, bukan janji durasi. Estimasi sprint harus dibuat setelah perangkat, jumlah developer, dan standar rilis dipastikan.

---

## 15. Risiko dan Mitigasi

| Risiko | Mitigasi |
|---|---|
| Antrean panjang saat jam masuk | UI kiosk ringan, uji beban, reader responsif, prosedur petugas |
| Internet putus | Pesan status jelas; prosedur manual terdokumentasi; offline ditunda sampai dirancang dengan aman |
| Kartu dipinjamkan | Pemeriksaan visual, kebijakan sekolah, dan opsi verifikasi alternatif |
| Kartu hilang | Pemblokiran cepat dan penerbitan ulang |
| Salah menetapkan alpa | Pisahkan “belum tercatat” dari “alpa”; gunakan verifikasi petugas |
| Kesalahan data impor | Validasi, preview, dan laporan baris gagal |
| Data bocor lintas sekolah | Tenant enforcement di backend, pengujian akses, dan audit |
| Wajah salah dikenali | Ambang verifikasi, uji akurasi, alternatif nonbiometrik, dan pemeriksaan petugas |
| Perangkat tidak kompatibel | Pilih perangkat sebelum implementasi dan uji prototipe |
| Laporan tidak konsisten | Pisahkan raw event dan ringkasan harian; tes perhitungan |

---

## 16. Asumsi dan Keputusan yang Dikunci

1. Produk bernama **Sentra Absensi Sekolah**.
2. Target mencakup SD, SMP, SMA/SMK, dan sekolah sederajat.
3. MVP menggunakan satu terminal bersama di gerbang per sekolah.
4. QR Code dan RFID adalah metode utama.
5. Pengenalan wajah hanya alternatif saat kartu tertinggal dan tidak wajib.
6. Sistem membutuhkan internet pada MVP; mode offline belum termasuk.
7. Portal orang tua dibuat setelah MVP.
8. Backend menjadi sumber waktu dan validasi transaksi.
9. Sekolah dapat mengatur jadwal dan toleransi keterlambatan.
10. Data antarsekolah wajib terisolasi.
11. Status “belum tercatat” tidak otomatis menjadi “alpa”.
12. Biometrik tidak diaktifkan untuk produksi sebelum persyaratan privasi, keamanan, dan alternatif nonbiometrik terpenuhi.

---

## 17. Definition of MVP Complete

MVP dapat dinyatakan selesai jika:

- Admin dapat membuat sekolah, kelas, jadwal, dan data siswa.
- Siswa dapat melakukan check-in dan check-out menggunakan QR.
- RFID berfungsi pada perangkat yang disepakati dan diuji.
- Scan ganda ditolak dengan respons yang jelas.
- Sistem menentukan keterlambatan dari aturan sekolah.
- Petugas memiliki prosedur kartu tertinggal yang aman.
- Dashboard dan laporan konsisten dengan transaksi.
- Koreksi manual memiliki audit trail.
- Isolasi tenant dan hak akses telah diuji.
- Backup, pemantauan, dan prosedur gangguan tersedia.
- Seluruh acceptance criteria kritis lulus pada lingkungan pilot.
- Dokumentasi operasional tersedia untuk admin dan petugas.

**Prinsip akhir produk:** Sentra Absensi harus mengutamakan pencatatan yang akurat, proses yang cepat di gerbang, data yang dapat diaudit, serta perlindungan siswa. Teknologi pengenalan wajah adalah opsi tambahan, bukan pengganti tata kelola data dan prosedur sekolah yang baik.
