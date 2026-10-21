# Panduan Admin Sekolah

Untuk pengguna dengan peran **Admin Sekolah**. Panduan ini mengikuti fitur yang sudah ada di aplikasi (sampai Tahap 5); bagian bertanda *(rencana)* belum tersedia.

## 1. Peran dan apa yang dapat dilakukan

| Peran | Ringkasan |
|---|---|
| Admin Sekolah | Mengelola semuanya: anggota, data akademik, siswa, jadwal, perangkat, izin, koreksi, laporan, audit. |
| Kepala Sekolah | Melihat data, kehadiran, laporan, dan audit. Tidak mengubah data. |
| Wali Kelas | Melihat dan mengelola **kelas yang ia ampu**: mengajukan izin dan koreksi, memverifikasi alpa kelasnya, mengekspor laporan kelasnya. |
| Petugas Piket | Membantu absensi manual, meninjau scan bermasalah, memverifikasi alpa, memutuskan izin. Tidak mengekspor laporan. |
| Operator Terminal | Hanya untuk akun terminal; tidak memakai dashboard. |

Menu yang tampil mengikuti izin peran Anda. Bila sebuah menu tidak ada, peran Anda memang tidak memilikinya.

## 2. Menyiapkan sekolah (urutan yang disarankan)

1. **Tahun ajaran** → buat dan aktifkan tahun ajaran berjalan.
2. **Guru** → tambahkan guru (wali kelas perlu akun; kirim **Undangan** dari menu Undangan).
3. **Kelas** → buat kelas per tahun ajaran dan pilih wali kelas.
4. **Siswa** → tambah satu per satu atau **impor CSV/XLSX**. Impor memvalidasi dulu; hanya baris yang valid yang disimpan, dan Anda melihat hasil per baris sebelum menyimpan.
4a. **Guru, mapel, dan jadwal mengajar** (opsional, data rujukan) → **Guru → Impor guru** (kolom `nomor_pegawai`, `nama_lengkap`, `mapel` dipisah titik koma, `email`; guru baru tidak otomatis punya akun login). Lalu **Jadwal mengajar → Impor jadwal** (kolom `hari`, `jam_mulai`, `jam_selesai`, `kelas`, `mapel`, `guru`; guru dicocokkan lewat nomor pegawai atau nama lengkap) atau tambah jam satu per satu. Satu guru atau satu kelas tidak boleh punya dua pelajaran di jam yang beririsan; baris yang bentrok ditolak saat impor. Impor guru dulu sebelum jadwal, dan buat kelasnya lebih dulu. Mapel yang belum ada dibuat otomatis; kelola daftarnya di **Jadwal mengajar → Mata pelajaran**. Jadwal mengajar belum dipakai untuk absensi per jam pelajaran.
5. **Jadwal** → jam masuk, batas terlambat, jam pulang per hari. **Libur** → hari libur nasional/sekolah. Cek hasilnya di **Kalender**.
6. **Perangkat** → pasang terminal (lihat §4).
7. **Kredensial** siswa (QR/kartu RFID) → cetak atau terbitkan dari halaman siswa. Kartu hilang: cabut lalu terbitkan yang baru; yang lama langsung tidak berlaku.

Siswa pindah kelas: ubah kelasnya; laporan lama tetap mencatat kelas pada hari itu.

## 3. Kehadiran harian

- **Status harian:** hadir, terlambat, izin, sakit, dispensasi, alpa sementara, alpa, belum tercatat.
- **Scan ditolak/perlu tinjauan** muncul di halaman **Kehadiran → Antrean tinjauan** (terima atau tolak dengan alasan).
- **Pencatatan mode bantuan (absensi manual)** hanya dengan alasan tercatat; semuanya masuk audit.
- Scan **mengalahkan izin**: bila siswa berstatus izin tetapi memindai kartu, status menjadi hadir/terlambat.

### Alpa: tiga langkah
1. Setelah jam penutupan (diatur di **Kehadiran → Aturan alpa**) dan hari dinilai sehat, siswa yang belum tercatat menjadi **alpa sementara** (otomatis, berdasarkan aturan).
2. Admin/petugas/wali kelas **memverifikasi** (satu per satu atau massal) → menjadi **alpa**.
3. Alpa hanya dihitung resmi di laporan setelah diverifikasi.

Bila terminal/jaringan bermasalah, tandai hari sebagai **gangguan**: aturan alpa **ditahan** supaya tidak ada siswa yang di-alpa-kan karena sistem. Lepaskan penahanan setelah pulih. Hari tanpa satu pun scan ditahan otomatis.

Scan yang masuk **setelah alpa final** tidak menimpa; masuk ke antrean tinjauan.

## 4. Perangkat terminal

1. **Perangkat → Tambah**, beri nama (mis. "Gerbang utama") → muncul kode pairing sekali pakai.
2. Buka halaman terminal di perangkat, masukkan kode. Perangkat terhubung dan mulai menerima scan QR/RFID.
3. Dashboard memberi peringatan bila perangkat **tidak terhubung**; scan di perangkat itu tidak tercatat.
4. Perangkat hilang/dicabut: **cabut** dari daftar; tokennya langsung tidak berlaku.

Jumlah perangkat aktif dibatasi per sekolah (sekolah demo: 1).

## 5. Izin, sakit, dispensasi

- Menu **Izin**: ajukan untuk siswa (rentang tanggal, alasan, lampiran opsional), lalu admin/petugas **menyetujui** atau **menolak**. Menyetujui langsung mengubah status harian untuk tanggal yang sudah berjalan; tanggal mendatang diterapkan saat hari ditutup.
- Lampiran bersifat privat dan hanya dibuka lewat aplikasi.
- Izin dapat dibatalkan; pembatalan untuk hari yang sudah lewat belum menjalankan ulang aturan alpa.

## 6. Koreksi absensi

Menu **Koreksi**:
- **Admin**: koreksi langsung (ubah status, putuskan pulang awal, atau koreksi jam masuk/pulang; status terlambat dan pulang awal dihitung ulang dari jadwal) dengan alasan minimal 10 karakter. Hanya untuk hari yang sudah berjalan.
- **Wali kelas**: mengajukan untuk siswa kelasnya; admin menyetujui/menolak. Absensi baru berubah setelah disetujui.
- Setiap koreksi tercatat di riwayat dan audit; data scan asli tidak pernah diubah.

## 7. Laporan dan ekspor

Menu **Laporan**: rekap per kelas, per siswa, keterlambatan, harian, bulanan per kelas, dan scan ditolak. Rumus persentase:

> kehadiran % = (hadir + terlambat) ÷ (hari efektif − izin − sakit − dispensasi)

Alpa sementara dan belum tercatat dilaporkan terpisah dan **tidak** dihitung sebagai alpa resmi.

**Ekspor** (CSV, XLSX, PDF) dibuat di latar belakang; tautan **Unduh** muncul di "Ekspor saya", berlaku 10 menit per klik halaman, dan berkas dihapus setelah 7 hari. PDF maksimal 2000 baris. Hanya Anda yang dapat mengunduh ekspor Anda. Berkas berisi data pribadi siswa: simpan seperlunya dan jangan dibagikan lewat kanal umum.

Dashboard memuat **tren kehadiran** harian (14/30/90 hari) dan pengingat alpa sementara yang menunggu verifikasi.

## 8. Audit

Menu **Audit** menampilkan siapa melakukan apa dan kapan (koreksi, keputusan izin, perangkat, impor, ekspor, dst.). Catatan audit tidak dapat diubah atau dihapus.

## 9. Keamanan akun

- Kata sandi kuat; aktifkan autentikasi dua langkah bila tersedia untuk akun Anda.
- Keluar dari komputer bersama. Jangan membagikan akun.
- Anggota yang berhenti bertugas: nonaktifkan dari menu **Anggota**.

## 10. Yang belum tersedia

Notifikasi ke orang tua, dan penarikan data otomatis saat server mati *(rencana)*. Bila hari terlewat penutupannya, hubungi pengelola teknis untuk menjalankan penutupan hari manual.
