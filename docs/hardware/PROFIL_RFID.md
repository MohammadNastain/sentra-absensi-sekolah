# Profil Normalisasi UID RFID

Status: **profil `generic` aktif; model reader belum dipilih (D-18) dan uji fisik H1–H11 belum dijalankan.**

## Profil `generic` (saat ini)
Implementasi: `apps/api/app/Domain/Credentials/RfidUid.php`.

| Aturan | Perilaku |
|---|---|
| Karakter dibuang | spasi, tab, `:`, `-` |
| Huruf | diubah ke kapital |
| Valid | `^[0-9A-F]{6,20}$` setelah dirapikan; selain itu ditolak (`invalid`) |
| Tidak dilakukan | konversi desimal↔hex, pembalikan urutan byte, pemangkasan nol di depan |
| Kunci hash | `rfid:<UID kanonik>` (tidak bertabrakan dengan token QR) |

**Konsekuensi:** kartu harus didaftarkan lewat reader yang keluarannya berformat sama dengan reader di terminal. Mengganti model reader dengan format berbeda (mis. desimal ke hex, atau byte terbalik) membuat kartu lama tidak cocok sampai dilakukan pendaftaran ulang atau ditambahkan profil khusus.

## Menambah profil khusus model
1. Jalankan protokol uji di `INTEGRASI_PERANGKAT.md` §3 pada model terpilih (minimal H1, H2, H5).
2. Dokumentasikan format keluaran mentah dan bentuk kanonik yang diinginkan di bawah ini.
3. Tambahkan konstanta profil dan aturan konversi pada `RfidUid` (+ tes dengan sampel UID nyata dari uji H1).
4. Simpan hasil uji di `docs/hardware/hasil/<tanggal>-<model>.md` (salin `hasil/TEMPLAT.md`).

## Profil terpilih
_Belum ada. Diisi setelah uji fisik pada reader pilihan pemilik produk._

| Model | Frekuensi | Format keluaran mentah | Profil | Syarat layout keyboard |
|---|---|---|---|---|
| _belum diisi_ | | | | |
