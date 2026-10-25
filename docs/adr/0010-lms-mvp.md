# ADR 0010 — LMS MVP: materi, tugas, pengumpulan, dan penilaian

**Status:** Diterima (26 Okt 2026). Keputusan §"Pertanyaan terbuka": akun siswa lewat NIS + kata sandi awal (bukan undangan email); unit LMS dari `teaching_slots`; penilaian oleh guru mapel **dan** Admin Sekolah; batas file dan satu-akun-satu-siswa sesuai usulan.
**Sumber:** PRD Sentra Sekolah v1.1 §5.5, §7 (alur LMS), §8 (LMS MVP), §12 (kriteria LMS); ADR 0009 (entitlement)

## Konteks

PRD v2 menetapkan LMS sebagai modul P2: guru menerbitkan materi dan tugas per kelas/mata pelajaran, siswa membaca dan
mengumpulkan, guru menilai atau meminta revisi. Fitur sudah terdaftar di registri entitlement (`lms`, `lms.materials`,
`lms.attachments`, `lms.assignments`, `lms.submissions`, `lms.grading`; belum dirilis) sehingga gerbang paket tidak perlu
dirancang ulang.

Dua kekosongan di kode saat ini membuat LMS tidak bisa dibangun langsung di atas data akademik yang ada:

1. **Siswa tidak punya akun.** `students` hanya baris data; tidak ada `membership_id` dan tidak ada peran siswa.
   Absensi tidak membutuhkannya (siswa dipindai terminal), LMS membutuhkannya.
2. **Tidak ada peran guru mata pelajaran.** Peran yang ada: `school_admin`, `principal`, `homeroom_teacher`,
   `duty_officer`, `terminal_operator`. `teachers.membership_id` sudah ada dan opsional (D-07), tetapi guru yang
   login hanya mendapat izin wali kelas.

Tiga hal yang harus tetap benar (PRD §12): guru hanya mengelola kelas/mapel yang ditugaskan; siswa hanya melihat yang
relevan; file tidak bisa diakses lintas sekolah atau tanpa izin. Waktu pengumpulan memakai waktu server.

## Keputusan

### 1. Peran dan akun
- Tambah peran **`teacher`** (guru mata pelajaran) dan **`student`**. Sinkron dengan seed `roles` dan CHECK
  `membership_invitations.role_codes`.
- Guru: dikaitkan lewat `teachers.membership_id` yang sudah ada. Satu orang bisa `homeroom_teacher` + `teacher`.
- Siswa: tambah `students.membership_id` (nullable, unik, FK komposit ke `school_memberships`), pola yang sama dengan
  guru. **Login siswa opsional**: sekolah yang tidak memakai LMS tidak terpengaruh.
- **Akun siswa dibuat Admin Sekolah dari NIS, tanpa email.** Model identitas tetap berbasis email, jadi akun siswa
  memakai alamat sintetis `<nis>.<kode-sekolah>@siswa.sentra.invalid` (TLD `.invalid` tidak pernah dapat dikirimi
  surat). Siswa tidak mengetik alamat itu: kolom login menerima **`<kode-sekolah>/<NIS>`** (mis. `smpn1/2024001`) dan
  server memetakannya ke alamat sintetis; bentuk email biasa tetap berlaku bagi pengguna lain.
- Kata sandi awal **dibangkitkan sistem** (acak, 10 karakter tanpa karakter membingungkan), ditampilkan **sekali** kepada
  admin (satu akun atau massal sebagai CSV/cetak lembar kredensial), tidak disimpan dalam bentuk terbaca, dan tidak
  masuk audit. `users.must_change_password` (kolom baru) memaksa siswa mengganti sandi pada login pertama; selama
  belum diganti, API hanya mengizinkan ganti sandi dan keluar.
- Tanpa email, **lupa sandi dilayani admin**: tombol "atur ulang sandi" menghasilkan sandi awal baru (audit
  `school.student_account.password_reset`) dan memaksa ganti lagi. Alur "lupa sandi" lewat email dinonaktifkan untuk
  alamat `.invalid`. Akun siswa tidak dapat mengaktifkan 2FA di MVP dan tidak memiliki keanggotaan sekolah lain.
- Satu akun = satu siswa (portal orang tua adalah modul P3 tersendiri). Menonaktifkan siswa (`status` bukan `active`)
  atau menghapus akunnya menutup login dan akses LMS; data pengumpulan tetap.
- Pembatasan percobaan login (throttle) memakai kunci `<kode-sekolah>/<NIS>` + IP agar satu kelas di jaringan sekolah
  yang sama tidak saling mengunci.
- Izin baru (granular, bukan per-peran di kode rute):

| Izin | `teacher` | `student` | `school_admin` | `principal` |
|---|---|---|---|---|
| `school.lms.view` (lihat konten yang relevan) | ya | ya | ya | ya |
| `school.lms.manage` (buat/ubah/terbitkan materi dan tugas) | ya, hanya mapel-kelasnya | tidak | ya, semua | tidak |
| `school.lms.submit` (kumpulkan tugas) | tidak | ya | tidak | tidak |
| `school.lms.grade` (nilai, komentar, minta revisi) | ya, hanya mapel-kelasnya | tidak | ya, semua | tidak |

  Admin ikut menilai (mis. guru berhalangan); penilaian oleh admin tercatat di audit dengan aktornya.

### 2. "Kelas-mapel" sebagai unit LMS (tanpa tabel penugasan baru)
Unit akses adalah pasangan **(kelas, mata pelajaran)**. Seorang guru mengelola pasangan yang muncul di
`teaching_slots` miliknya; siswa melihat pasangan untuk kelas tempat ia terdaftar aktif (`class_enrollments` berjalan).
Alasan: PRD meminta menghindari duplikasi dan `teaching_slots` sudah menyatakan "siapa mengajar apa di kelas mana".
Konsekuensi: menghapus semua slot sebuah guru untuk pasangan itu mencabut hak kelolanya atas konten lama (konten tetap
tersimpan, hanya admin yang bisa mengelolanya). Bila kelak butuh penugasan tanpa jadwal, tabel `teaching_assignments`
bisa ditambahkan tanpa mengubah kontrak API (resolver izin dipusatkan di satu kelas, `LmsScope`).

### 3. Model data (semua tabel tenant: `school_id`, RLS, FK komposit `(school_id, id)`)
- `lms_materials`: `class_id`, `subject_id`, `author_teacher_id`, `title`, `body` (teks), `status`
  (`draft`/`published`), `published_at`. Tanpa HTML bebas: teks polos agar tidak ada XSS tersimpan.
- `lms_assignments`: kunci kelas-mapel sama, `title`, `instructions`, `due_at` (timestamptz, server), `max_score`,
  `accept_late` (bool), `status` (`draft`/`published`/`closed`).
- `lms_submissions`: `assignment_id`, `student_id`, `body`, `status`
  (`submitted`/`returned_for_revision`/`graded`), `submitted_at` (waktu server), `is_late` (dihitung server saat
  kirim), `score`, `feedback`, `graded_by_user_id`, `graded_at`. Unik `(assignment_id, student_id)`: satu pengumpulan per
  siswa; revisi menimpa isi setelah guru mengembalikan, dan riwayat perubahan tercatat di audit.
- `lms_attachments`: `owner_type` (`material`/`assignment`/`submission`), `owner_id`, nama asli, `storage_key`, `mime`,
  `size`, `sha256`, `uploaded_by_user_id`. Satu tabel untuk tiga pemilik; FK ditegakkan lewat CHECK dan trigger
  validasi pemilik di sekolah yang sama.
- Status `LATE` dari PRD tidak jadi status; ia atribut `is_late`, karena terlambat dan dinilai bisa terjadi bersamaan.

### 4. Aturan alur
- Konten `draft` hanya terlihat penulis dan admin; siswa hanya melihat `published`.
- Siswa mengumpulkan hanya sebelum `due_at`, atau sesudahnya bila `accept_late`; tugas `closed` menolak. Kelebihan
  tenggat menandai `is_late = true`.
- Mengubah `due_at` tidak mengubah `is_late` pengumpulan yang sudah ada.
- Guru: `graded` (nilai 0..`max_score`, komentar opsional) atau `returned_for_revision` (komentar wajib). Siswa hanya
  melihat nilai dan komentar miliknya setelah `graded`/`returned`.
- Nonaktifnya modul (entitlement) mencabut akses operasional; data tidak dihapus (ADR 0009).

### 5. File
- Disk privat khusus `lms` (lokal di pengembangan, S3-kompatibel di produksi); **tidak ada URL publik**.
- Kunci simpan: `school_id/uuid`, tanpa nama asli. Nama asli hanya metadata untuk header unduhan.
- Validasi: ekstensi allowlist (pdf, doc/docx, xls/xlsx, ppt/pptx, txt, csv, jpg, png), MIME hasil sniffing harus cocok,
  maks 10 MB per file dan 5 file per pemilik; nama dibersihkan.
- Unduhan lewat endpoint API yang memeriksa **autentikasi → keanggotaan sekolah → entitlement `lms.attachments` →
  izin → keterlibatan pada konten** (guru: kelas-mapelnya; siswa: kelasnya dan konten terbit, atau lampiran
  pengumpulannya sendiri). Respons `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`.
- Tidak ada tautan bertanda tangan di MVP (selalu lewat pemeriksaan penuh). Pemindaian malware, kuota per sekolah, dan
  kebijakan retensi file dicatat sebagai pekerjaan lanjutan; MVP membatasi risiko dengan allowlist dan unduhan paksa.

### 6. Entitlement dan rute
Rute ber-`school.feature` sesuai kode registri (any-of untuk halaman gabungan); lampiran digerbang `lms.attachments`
termasuk saat mengunduh. Dependensi sudah ada di registri (submissions → assignments, grading → submissions). Menandai
fitur "rilis" dilakukan di PR terakhir tahap ini, setelah UI dan tes lengkap.

### 7. Audit
Dicatat (audit sekolah): penerbitan/penutupan konten, penilaian, pengembalian untuk revisi, penghapusan lampiran.
Tidak dicatat: draf tersimpan dan unduhan biasa. Nilai dan komentar tidak masuk `before/after` mentah bila
`AuditRedactor` tidak mengizinkan; allowlist ditambah secara eksplisit.

### 8. Pengujian (kriteria PRD §12 menjadi tes)
Negatif lintas sekolah untuk setiap rute dan unduhan; guru tanpa slot ditolak; siswa kelas lain ditolak; draft tak
terlihat siswa; tenggat memakai waktu server (tes dengan jam dibekukan); mematikan fitur mencabut akses termasuk file;
matriks rute (T08/T09) mencakup rute baru.

### 9. Tahapan
| Tahap | Isi | Risiko |
|---|---|---|
| L1a | Peran `teacher`/`student`, izin, `students.membership_id`, `LmsScope` | sedang |
| L1b | Akun siswa: pembuatan dari NIS (satu/massal), login `<kode>/<NIS>`, ganti sandi paksa, atur ulang oleh admin, throttle | tinggi (menyentuh login) |
| L2 | Materi + lampiran: API, disk privat, unduhan aman, tes | sedang |
| L3 | Tugas, pengumpulan, penilaian/revisi: API + tes | sedang |
| L4 | UI guru (materi, tugas, penilaian) | rendah |
| L5 | UI siswa (materi, tugas, kumpul, nilai) dan menu per peran | rendah |
| L6 | Pengerasan: tes negatif menyeluruh, rilis fitur di registri, dokumen | rendah |

## Konsekuensi
- Pertama kalinya ada pengguna siswa; halaman web dan menu harus sadar peran (siswa tidak melihat menu admin).
  Login, 2FA, dan kebijakan kata sandi mengikuti akun yang ada; 2FA tidak diwajibkan bagi siswa (hanya Super Admin).
- Data pribadi anak: nilai dan komentar adalah data sensitif; akses dibatasi seperti di §1 dan tidak muncul di audit
  mentah. Perlu masuk tinjauan hukum D-26 sebelum dipakai sekolah sungguhan.
- Penyimpanan file menambah biaya dan beban operasional (backup, retensi) yang belum dihitung di paket harga.

## Pertanyaan terbuka
Tidak ada. Hal yang sengaja ditunda: pemindaian malware dan kuota file per sekolah, notifikasi tugas/nilai,
portal orang tua, 2FA untuk siswa, dan penugasan guru lepas dari jadwal (`teaching_assignments`).
