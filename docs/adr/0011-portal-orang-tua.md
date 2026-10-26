# ADR 0011 — Portal orang tua/wali

**Status:** Diterima (26 Okt 2026). Semua pertanyaan di §"Keputusan atas pertanyaan terbuka" disetujui sesuai usulan.
**Sumber:** PRD Sentra Sekolah v1.1 §1.2 (portal orang tua di luar MVP, modul P3), registri entitlement `parent_portal` (ADR 0009); ADR 0004 (keanggotaan), ADR 0005 (kehadiran), ADR 0008 (surel), ADR 0010 (LMS, notifikasi §8); D-26, D-27, R11 (`DECISIONS.md`)

## Konteks

Orang tua/wali ingin tahu apakah anaknya hadir, mengajukan izin tanpa datang ke sekolah, dan (kelak) melihat tugas dan nilai.
Hari ini tidak ada konsep wali di data: `students` hanya punya akun opsional untuk siswa sendiri (ADR 0010), `guardians` dan
`student_guardians` sengaja ditunda (D-27) agar tidak ada tabel kosong yang menambah permukaan data pribadi. Sekarang
kebutuhannya nyata, dan notifikasi dalam aplikasi sudah ada (ADR 0010 §8).

Tiga hal yang harus tetap benar: wali hanya melihat **anaknya sendiri** (bukan seisi kelas), tidak ada jalur yang
memperluas hak guru atau admin, dan data anak adalah data pribadi anak (UU PDP; D-26, R11) sehingga bagian yang paling
sensitif (nilai dan komentar) ikut gerbang rilis yang sama dengan LMS.

## Keputusan

### 1. Peran dan akun
- Tambah peran **`guardian`** (wali). Sinkron dengan seed `roles` dan CHECK `membership_invitations.role_codes`.
- Wali memakai **akun surel sungguhan** lewat alur undangan yang sudah ada (ADR 0004, 0008): Admin Sekolah memasukkan nama,
  surel, dan hubungan, lalu wali menerima tautan, membuat sandi, dan masuk. Surel dibutuhkan agar "lupa sandi" bekerja
  tanpa campur tangan admin (berbeda dengan akun siswa tanpa surel). Wali dapat mengaktifkan 2FA (opsional, tidak diwajibkan).
- Satu orang boleh menjadi wali beberapa anak, di kelas dan jenjang berbeda dalam sekolah yang sama, dan boleh punya
  keanggotaan di beberapa sekolah (model keanggotaan sudah mendukung). Satu orang boleh juga guru: peran
  digabung pada satu keanggotaan, tetapi hak wali **hanya berlaku di rute portal** (§4), tidak pernah memperluas hak guru.
- Akun wali tidak punya izin LMS/akademik/kehadiran operasional; yang dimilikinya hanya izin portal:

| Izin | `guardian` | peran lain |
|---|---|---|
| `school.portal.view` (lihat data anak sendiri) | ya | tidak |
| `school.portal.leave` (ajukan izin untuk anak sendiri) | ya | tidak |
| `school.guardians.manage` (tambah/ubah/lepas wali, kirim ulang undangan) | tidak | `school_admin` |

### 2. Model data (tabel tenant: `school_id`, RLS, FK komposit `(school_id, id)`)
- `guardians`: `id`, `full_name`, `phone` (opsional, teks polos, tidak dipakai mengirim apa pun), `membership_id`
  (nullable, unik; terisi setelah undangan diterima; pola sama dengan `teachers`/`students`), `status` (`active`/`inactive`).
- `student_guardians`: `student_id`, `guardian_id`, `relationship` (`ayah`/`ibu`/`wali`), `is_primary`, `created_at`.
  Unik `(student_id, guardian_id)`; maksimal **4 wali per siswa** (ditegakkan aplikasi, dicek di tes).
- Perluasan `notifications` (ADR 0010 §8): tipe baru §5. Tabelnya sama; penerima tetap `user_id`.
- Tanpa kolom surel di `guardians`: surel hidup di `users`/undangan (satu sumber kebenaran).

### 3. Yang dilihat wali (bertahap, tiap bagian punya fitur sendiri)
| Bagian | Isi | Fitur | Rilis |
|---|---|---|---|
| Anak | nama, NIS, kelas saat ini | `parent_portal` | ikut G3 |
| Kehadiran | status harian per tanggal, jam masuk/pulang, ringkasan bulan, alasan izin | `parent_portal.attendance` | G3 |
| Izin | daftar dan status pengajuan, ajukan izin/sakit baru | `parent_portal.leave` | G4 |
| Tugas dan nilai | tugas kelas anak, status pengumpulan, nilai dan komentar | `parent_portal.lms` | **tidak dirilis** sampai tinjauan D-26 selesai (sama dengan `LMS_RELEASED`) |

Wali **tidak** melihat: siswa lain, daftar kelas, nama teman, data perangkat/terminal, kredensial kartu/QR, log audit,
atau data guru selain nama pengajar pada tugas anaknya. Komentar guru hanya milik anaknya sendiri dan hanya di bagian LMS.

### 4. Pembatasan akses (`GuardianScope`)
- Rute portal diberi awalan `schools/{school}/portal/...` dan selalu melewati `GuardianScope`, yang menjawab
  "anak-anak milik keanggotaan ini": siswa dengan `status = active` dan baris `student_guardians` ke wali berstatus aktif
  yang `membership_id`-nya keanggotaan pemanggil. Pola sama dengan `LmsScope`.
- Id siswa di URL yang bukan anaknya dijawab **404** (bukan 403), sama seperti LMS, agar keberadaan siswa tidak bocor.
- Tidak ada endpoint wali yang menerima `student_id` di body tanpa lewat scope. Daftar tidak pernah difilter di klien.
- Siswa nonaktif hilang dari portal (konsisten dengan penutupan login siswa, ADR 0010 §1); riwayat tetap tersimpan di sekolah.
- Wali dinonaktifkan atau dilepas dari anak → akses anak itu tertutup seketika; sesi wali lain tidak terpengaruh.

### 5. Pengajuan izin oleh wali (G4)
- Memakai tabel dan alur `leave_requests` yang ada: wali membuat pengajuan `pending` (jenis `izin`/`sakit`/`dispensasi`,
  rentang tanggal, alasan); wali kelas/petugas/admin menyetujui atau menolak seperti biasa, dan persetujuan memicu
  penghitungan ulang kehadiran (ADR 0005).
- Tambah kolom `submitted_by_guardian_id` (nullable) agar jelas siapa yang mengajukan. Wali hanya dapat **membatalkan**
  pengajuan miliknya selama masih `pending`; tidak dapat mengubah yang sudah diputuskan.
- Tanpa lampiran di MVP portal (surat dokter dll. nanti): menghindari unggahan dari akun publik yang lebih luas
  daripada akun sekolah. Rentang tanggal dibatasi (tidak di masa lalu lebih dari 7 hari, maksimal 30 hari per pengajuan).
- Pembatasan laju per wali (mis. 10 pengajuan/hari) agar tidak jadi jalur spam.

### 6. Notifikasi untuk wali (G7, tidak termasuk rilis awal)
Portal dirilis **lihat-saja dulu**; G7 dikerjakan terpisah setelah portal dipakai dan sinyalnya terbukti benar.
Memakai tabel dan lonceng ADR 0010 §8, endpoint disamaratakan dari `lms/notifications` ke `notifications` (digerbang
`notifications`; `lms/notifications` tetap sebagai alias sampai klien berpindah). Tipe baru: `attendance.absent`
(alpa terverifikasi), `attendance.late` (terlambat), `leave.decided` (izin disetujui/ditolak). **Tidak** ada notifikasi
untuk status sementara `alpa_belum_diverifikasi`, karena hari itu belum final dan kesalahan sinyal ke orang tua mahal.
Tidak ada surel, WhatsApp, atau push di tahap ini (butuh layanan dan biaya yang belum diputuskan, D-23).

### 7. Entitlement
Daftarkan `parent_portal.attendance`, `parent_portal.leave`, `parent_portal.lms` sebagai anak `parent_portal`
(sekarang hanya induknya yang terdaftar). Prasyarat: `parent_portal.attendance` butuh `attendance`; `.leave` butuh
`attendance.leave`; `.lms` butuh `lms.assignments`. Penempatan di paket: attendance + leave di Standard dan Premium,
lms di Premium (diputuskan, lihat bawah). Rilis lewat `config(sentra.parent_portal_released)`
(env `PARENT_PORTAL_RELEASED`), mati sampai G8; sekolah demo dikecualikan seperti LMS.

### 8. Audit dan privasi
- Dicatat di audit sekolah: wali ditambahkan/dilepas/dinonaktifkan, undangan wali dikirim ulang, pengajuan izin wali dibuat
  dan dibatalkan. Tidak dicatat: wali membuka halaman (hanya baca; log akses mentah memperbesar data pribadi tanpa nilai).
- Isi surel undangan wali tidak memuat nama anak (ADR 0008 §4). Nomor telepon tidak ditampilkan ke wali lain.
- Wali dapat melihat siapa saja wali lain anaknya (nama dan hubungan saja) agar tidak ada wali "siluman"; Admin yang bertanggung
  jawab memverifikasi identitas wali sebelum mengundang (SOP sekolah; dicantumkan di panduan admin).
- Retensi mengikuti D-26: wali nonaktif disimpan sebagai riwayat sampai siswanya dihapus; tautan wali-anak ikut dihapus
  bersama siswa.

### 9. Pengujian
Negatif lintas sekolah per rute portal; wali tidak dapat melihat anak orang lain (404) walau menebak id; wali tidak dapat
memakai rute guru/admin; guru yang juga wali tidak melihat siswa non-anaknya lewat rute portal dan tidak mendapat hak
guru tambahan; siswa nonaktif hilang dari portal; wali dilepas kehilangan akses seketika; batas 4 wali; pengajuan izin
oleh wali memicu alur persetujuan yang sama; pembatalan hanya milik sendiri dan hanya saat `pending`; fitur dicabut
menutup rute; matriks rute (T08/T09) mencakup rute baru; undangan wali tidak membocorkan nama anak.

### 10. Tahapan
| Tahap | Isi | Risiko |
|---|---|---|
| G1 | Peran `guardian`, izin, tabel `guardians`/`student_guardians`, `GuardianScope`, fitur `parent_portal.*` | sedang |
| G2 | API admin: tambah wali + undangan, tautkan wali ke anak lain, lepas, nonaktifkan; audit | sedang |
| G3 | API portal baca: anak dan kehadiran | sedang (hak akses data anak) |
| G4 | Pengajuan dan pembatalan izin oleh wali | sedang |
| G5 | Web: menu dan beranda wali, pemilih anak, halaman kehadiran dan izin | rendah |
| G6 | Web admin: kelola wali dari halaman siswa; kolom wali di impor siswa (opsional) | rendah |
| G7 | Notifikasi wali (alpa, terlambat, izin diputuskan); **setelah rilis awal** | sedang (kebenaran sinyal) |
| G8 | Pengerasan, dokumen panduan wali/admin, demo, bendera rilis | rendah |
`parent_portal.lms` dikerjakan terpisah setelah D-26 selesai dan LMS dirilis.

## Konsekuensi
- Pertama kalinya akun non-staf/non-siswa melihat data anak; kebenaran `GuardianScope` adalah titik kritis dan wajib punya tes negatif lengkap.
- Surel wali menjadi syarat; sekolah tanpa surel wali tidak terlayani di tahap ini (keputusan a).
- Menambah beban dukungan (lupa sandi wali, salah tautan anak); admin memerlukan panduan dan layar yang jelas.
- Perlu masuk tinjauan hukum D-26/R11: dasar pemrosesan, persetujuan, dan retensi data anak untuk wali.

## Keputusan atas pertanyaan terbuka
a. **Surel wajib** untuk akun wali; akun tanpa surel dari NIS anak tidak dibuat. Dapat ditinjau ulang bila pilot menunjukkan banyak wali tanpa surel.
b. **Paket:** `parent_portal.attendance` dan `.leave` di Standard dan Premium; `parent_portal.lms` di Premium (tetap tertahan oleh rilis).
c. **Lihat-saja dulu:** rilis awal tanpa notifikasi otomatis; G7 menyusul.
d. **Maksimal 4 wali per anak**; hubungan `ayah`/`ibu`/`wali`.
e. **Hukum:** bagian kehadiran dan izin boleh dipakai sekolah pilot sebelum tinjauan D-26/R11 selesai, dengan dasar persetujuan dan SOP verifikasi wali oleh sekolah; nilai dan komentar tetap tertahan sampai tinjauan itu selesai. Jadwal tinjauan menunggu pihak hukum.
