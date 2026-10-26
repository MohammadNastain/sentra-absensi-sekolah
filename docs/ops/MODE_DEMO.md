# Mode demo

Tombol **Coba demo** di landing page memasukkan pengunjung sebagai admin sebuah sekolah contoh (`demo-sentra`) tanpa sandi.
Sekolah itu berisi data fiktif selengkap sekolah sungguhan: 252 siswa, 24 guru, 9 kelas, jadwal mengajar, riwayat absensi
14 hari sekolah, izin, kartu RFID, serta materi, tugas, dan nilai LMS.

## Menyalakan

1. API: `DEMO_ENABLED=true`, lalu `php artisan demo:reset` (butuh sekitar 10 detik). Scheduler membangun ulang tiap hari pukul 04:30.
2. Web: `NEXT_PUBLIC_DEMO_ENABLED=true` **sebelum build** (nilai ini ikut terbakar ke bundel). Tanpa itu tombol tidak tampil.
3. Matikan kapan saja dengan `DEMO_ENABLED=false`: `POST /auth/demo` menjadi 404 dan semua pengaman demo berhenti berlaku.

## Cara kerja dan pengaman

- Akun admin demo (`admin@demo.sentra.test`) dibuat dengan sandi acak yang tidak pernah disimpan, jadi satu-satunya jalan masuk adalah
  tombol demo (`POST /auth/demo`, dibatasi 10/menit per IP). Tidak ada 2FA.
- Akun itu hanya anggota sekolah demo; isolasi data antar sekolah tetap dijaga RLS.
- Sandi dan 2FA akun demo tidak dapat diubah (`DEMO_RESTRICTED`), dan menu Keamanan akun disembunyikan.
- Sekolah demo tidak mengirim surel: undangan ditandai gagal, tautan reset sandi anggota ditolak, "lupa password" untuk akun demo diabaikan.
- Unggah berkas (lampiran, impor) ditolak di sekolah demo agar penyimpanan server tidak bisa dipenuhi.
- Modul LMS tampil di sekolah demo walau flag rilis global (`LMS_RELEASED`) masih mati; sekolah lain tidak terpengaruh (`Feature::releasedInDemo`).
- Sekolah demo berlangganan paket Premium.

## Rotasi harian

Tabel absensi dan audit bersifat append-only sehingga sekolah demo tidak bisa dihapus. `demo:reset` mengganti kode sekolah lama
menjadi `demo-arsip-<tanggal>` (status `terminated`, NPSN dilepas) lalu membuat sekolah baru dengan kode `demo-sentra`.
Konsekuensinya sekolah arsip menumpuk (satu per hari) dan terlihat di daftar sekolah Super Admin; kira-kira 8.000 baris event
absensi per hari. Bila terlalu ramai, jalankan reset lebih jarang atau tambahkan penyaringan status `terminated` di daftar sekolah.

Perubahan yang dibuat pengunjung hilang saat reset berikutnya.
