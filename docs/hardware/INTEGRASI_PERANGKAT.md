# Integrasi Perangkat Gerbang — Abstraksi & Kebutuhan Uji Kompatibilitas

**Status:** Belum ada perangkat yang diuji. **Tidak ada model yang dinyatakan kompatibel.**
Arah desain: [ADR 0006](../adr/0006-abstraksi-input-perangkat.md).

## 1. Kontrak abstraksi (rancangan, diimplementasikan Tahap 3–4)

### Terminal (TypeScript)
```ts
type InputSourceKind = 'keyboard_wedge' | 'simulated'; // ditambah: 'webhid' | 'webserial' | 'camera'

interface RawCredentialInput {
  value: string;               // apa adanya setelah trim; tidak di-log di klien
  source: InputSourceKind;
  capturedAt: string;          // jam klien — diagnostik saja, bukan waktu absensi
  keystrokeTimingMs?: number[]; // diagnostik: membedakan scanner vs ketikan
}

interface InputSource {
  start(onInput: (input: RawCredentialInput) => void): void;
  stop(): void;
  readonly status: 'ready' | 'unfocused' | 'error';
}
```
`SimulatedSource` hanya dibundel di build development/test (dicek oleh test build produksi).

### Backend (PHP)
```php
interface RfidUidNormalizer {
    /** @throws InvalidCredentialInput */
    public function normalize(string $raw): string; // bentuk kanonik untuk HMAC
}
// ReaderProfile: 'generic' (awal). Profil model nyata ditambah setelah uji fisik.
// CredentialInputClassifier: 'qr' (prefiks SA1.) | 'rfid' | 'invalid'
```
Profil reader disimpan pada `devices.reader_profile` dan `student_credentials.reader_profile`. Kartu yang didaftarkan lewat satu profil tetap dapat dicocokkan walau reader terminal diganti, asalkan normalisasinya menghasilkan bentuk kanonik yang sama. Ini **wajib diuji** saat mengganti model reader.

## 2. Pertanyaan yang harus terjawab sebelum memilih hardware
1. Frekuensi kartu: 125 kHz (mis. EM4100) atau 13,56 MHz (MIFARE/NFC)? Kartu apa yang sudah dimiliki sekolah pilot?
2. Antarmuka reader: USB HID keyboard wedge? Format output dapat dikonfigurasi?
3. Format UID: desimal/hex, panjang, urutan byte, nol di depan, sufiks Enter/Tab.
4. Apakah output dipengaruhi layout keyboard OS?
5. Perilaku saat kartu ditahan di reader: berulang atau sekali?
6. Driver yang dibutuhkan di Windows/ChromeOS/Android?
7. Untuk scanner QR: mendukung QR 2D (bukan hanya barcode 1D)? Mode kiosk/continuous scan?
8. Ketersediaan, garansi, dan harga untuk pengadaan sekolah.

## 3. Protokol uji kompatibilitas (per model)
Dijalankan pada perangkat terminal target (laptop/PC prototipe), browser kiosk, dan build terminal yang akan dirilis.

| # | Uji | Kriteria lulus |
|---|---|---|
| H1 | Baca 20 kartu berbeda, masing-masing 3x | UID ternormalisasi identik untuk setiap kartu; tidak ada dua kartu dengan UID sama |
| H2 | Bandingkan dengan reader referensi/aplikasi vendor | Nilai kanonik cocok |
| H3 | 50 tap beruntun dengan ritme gerbang (~1 per 2 detik) | 0 input hilang, 0 input tergabung/terpotong |
| H4 | Kartu ditahan 5 detik | Tidak menghasilkan absensi ganda (backend: duplicate) |
| H5 | Layout keyboard OS US vs Indonesia | Output identik, atau persyaratan layout didokumentasikan |
| H6 | Fokus browser hilang (klik di luar, notifikasi OS) | Terminal menampilkan "tidak siap", tidak menerima input diam-diam |
| H7 | Cabut-pasang USB saat berjalan | Pulih tanpa restart, atau prosedur didokumentasikan |
| H8 | Restart perangkat | Kiosk kembali ke layar "Siap memindai" |
| H9 | Kartu rusak/tidak terdaftar | Pesan "tidak dikenal", tidak ada data siswa yang tampil |
| H10 | (QR) Cetak QR di kertas & layar HP, berbagai jarak/cahaya | Terbaca ≥ 95% dalam 1 percobaan |
| H11 | Waktu baca → hasil tampil di layar (jaringan sekolah) | p95 ≤ 2 detik end-to-end |

## 4. Templat hasil uji
Simpan sebagai `docs/hardware/hasil/<tanggal>-<model>.md`:
```text
Model / firmware:
Frekuensi & jenis kartu:
Perangkat terminal / OS / browser:
Versi build terminal & API:
Profil normalisasi:
Hasil H1–H11 (lulus/gagal + catatan):
Penguji & tanggal:
Kesimpulan: kompatibel / kompatibel dengan syarat / tidak kompatibel
```

## 5. Pengujian tanpa hardware (tersedia sejak Tahap 3)
- **Keyboard:** ketik token QR / UID lalu Enter di layar terminal (dev). Jalurnya sama dengan scanner wedge.
- **Simulasi:** `SimulatedSource` + helper Playwright yang mengetik dengan ritme scanner.
- Uji tanpa hardware **tidak** menggantikan protokol §3.
