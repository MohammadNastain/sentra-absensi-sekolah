# ADR 0004 — Identitas Global, Keanggotaan Sekolah, dan Pemilihan Tenant

- **Status:** Sebagian diterima, sebagian **diusulkan**
  - Diterima (keputusan pemilik produk, 17 Okt 2026): email unik global; identitas dipisah dari keanggotaan sekolah dan peran; satu user dapat mengakses beberapa sekolah; otorisasi berdasarkan sekolah yang sedang dipilih.
  - Diusulkan, **perlu persetujuan sebelum implementasi Tahap 1**: mekanisme pemilihan sekolah (D-34) dan pengelolaan identitas lewat undangan email (D-37).
- **Menggantikan:** rancangan awal `users.school_id` di PRD §7.1 dan DATABASE_DESIGN versi pertama.

## Keputusan
1. **`users` = identitas global**: email, nama, password, 2FA, status platform. Tidak memiliki `school_id`.
2. **`school_memberships`** menghubungkan user ↔ sekolah (status `invited | active | suspended | ended`). **`membership_roles`** menyimpan peran **per keanggotaan**, sehingga seseorang bisa menjadi Wali Kelas di sekolah A dan Petugas Piket di sekolah B.
3. **`platform_admins`** (tabel terpisah) menandai Super Admin. Super Admin tidak otomatis memiliki keanggotaan sekolah mana pun.
4. **Pemilihan tenant lewat path API** (usulan D-34): `/api/v1/schools/{school}/…`. Middleware memverifikasi keanggotaan aktif user pada `{school}` di **setiap request** dan menolak dengan 404 bila tidak ada. URL dashboard juga memuat sekolah (`/s/{school}/…`), sehingga dua tab dengan sekolah berbeda tidak saling tertukar.
5. **Identitas hanya dikelola pemiliknya** (usulan D-37). Admin Sekolah mengelola *keanggotaan* (undang, ubah peran, tangguhkan, akhiri), bukan *identitas*. Admin Sekolah tidak bisa mengganti password atau email user, karena identitas yang sama mungkin dipakai di sekolah lain.

## Alternatif yang ditolak untuk pemilihan tenant
| Alternatif | Alasan ditolak |
|---|---|
| Sekolah aktif disimpan di sesi server | Dua tab dengan sekolah berbeda berbagi sesi, sehingga aksi di tab lama bisa diterapkan ke sekolah yang salah. |
| Header `X-School-Id` | Bisa dipakai, tetapi tenant tidak terlihat di URL/log/cache dan lebih mudah terlewat dalam matriks test. |
| Subdomain per sekolah | Menambah kompleksitas cookie, CORS, dan sertifikat; tidak dibutuhkan di MVP. |

## Mengapa undangan email diperlukan (D-37)
Tanpa verifikasi kepemilikan email, akun lintas sekolah membuka celah pengambilalihan akses:
1. Admin sekolah B membuat akun untuk `guru@contoh.id` dengan password sementara yang ia ketahui.
2. Kemudian sekolah A mengundang `guru@contoh.id`.
3. Admin B login sebagai akun itu, menerima undangan A, dan mendapat akses ke data sekolah A.

Karena itu:
- User baru dibuat melalui **tautan undangan** yang dikirim ke email. Penerima menetapkan password sendiri, dan ini sekaligus membuktikan kepemilikan email.
- Bila email sudah terdaftar, undangan muncul setelah pemilik akun login dan harus diterima secara eksplisit.
- Reset password dilakukan sendiri lewat email.

**Konsekuensi:** dibutuhkan pengiriman email sejak Tahap 1. Di dev memakai Mailpit (lokal). Penyedia email produksi dipilih sebelum pilot dan tidak dikunci sekarang.

**Bila D-37 ditolak** (tanpa email di MVP): MVP harus membatasi satu user hanya pada satu sekolah secara fungsional (skema tetap mendukung banyak sekolah). Admin membuat akun dengan password sementara yang wajib diganti saat login pertama, dan dukungan multi-sekolah diaktifkan setelah verifikasi email tersedia.

## Konsekuensi untuk RLS
`school_memberships` dan `membership_roles` harus bisa dibaca dalam dua konteks: konteks sekolah (`app.school_id`) dan konteks user (`app.user_id`) untuk daftar "sekolah saya". Policy-nya:
`school_id = current_setting('app.school_id', true)::uuid OR user_id = current_setting('app.user_id', true)::uuid` (khusus SELECT). Penulisan tetap hanya dalam konteks sekolah.
