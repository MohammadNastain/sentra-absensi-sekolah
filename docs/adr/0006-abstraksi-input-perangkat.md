# ADR 0006 — Abstraksi Input Perangkat (QR/RFID) Tanpa Mengunci Model Hardware

- **Status:** Diterima sebagai arah desain (17 Okt 2026). Implementasi di Tahap 3 (QR) dan Tahap 4 (RFID).
- **Konteks:** Frekuensi kartu RFID dan model reader belum ditentukan. Hardware belum dibeli. Prototipe terminal memakai laptop/PC, dan scanner QR USB dipakai hanya bila tersedia dan kompatibel. Alur QR harus tetap bisa diuji dengan keyboard atau simulasi.

## Keputusan
Dua lapis abstraksi. Detail dan protokol uji ada di [`docs/hardware/INTEGRASI_PERANGKAT.md`](../hardware/INTEGRASI_PERANGKAT.md).

1. **Terminal (frontend): `InputSource`.** Setiap sumber input menghasilkan `RawCredentialInput { value, source, capturedAt, keystrokeTimingMs? }`.
   - `KeyboardWedgeSource`: untuk scanner QR USB, reader RFID USB HID, maupun ketikan manual. Ketiganya sama-sama "mengetik + Enter".
   - `SimulatedSource`: hanya tersedia di build `development`/`test`. Dipakai untuk E2E dan demo tanpa hardware.
   - Sumber lain (WebHID, Web Serial, kamera) ditambahkan tanpa mengubah alur scan.
2. **Backend: `CredentialInputClassifier` + `RfidUidNormalizer`.** Server menentukan apakah input adalah token QR (format `SA1.…`) atau UID RFID, lalu menormalisasi UID dengan **profil reader** yang dipilih per device. Profil awal hanya `generic` (trim, uppercase hex, tanpa pemisah). Profil untuk model nyata ditambahkan **setelah** uji fisik, dengan vektor uji dari pembacaan kartu sungguhan.

Ketikan manual **tidak diblokir** di level input karena UID/QR bukan rahasia dan pemblokiran berbasis kecepatan ketik bukan kontrol keamanan. Data timing hanya disimpan sebagai metadata diagnostik.

## Konsekuensi
- Tidak ada kode yang mengasumsikan frekuensi 125 kHz atau 13,56 MHz.
- Registrasi kartu RFID di dashboard memakai jalur `KeyboardWedgeSource` yang sama.
- Klaim "kompatibel" untuk sebuah model hanya boleh dibuat setelah protokol uji lulus dan hasilnya dicatat di `docs/hardware/`.
