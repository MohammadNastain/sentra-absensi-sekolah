# ADR 0008 — Layanan Email (Development & Kebutuhan Production)

- **Status:** Diterima untuk development. Penyedia production **belum dipilih** (ikut kajian PDP & pengadaan, D-23).
- **Konteks:** Undangan keanggotaan dan reset password bergantung pada email (ADR 0004, D-37). Sistem tidak boleh mengklaim email terkirim bila layanan email belum dikonfigurasi.

## Development
- **Mailpit** (`axllent/mailpit:v1.31.2`) di Docker Compose. SMTP di `mailpit:1025`, UI web di `http://127.0.0.1:8025`. Email tidak keluar dari mesin lokal.
- Test otomatis memakai `Mail::fake()` / `Notification::fake()`. E2E memakai API Mailpit untuk mengambil tautan undangan.

## Aturan jujur soal pengiriman
1. Setiap undangan menyimpan `delivery_status`: `queued`, `handed_off` (transport SMTP/API menerima pesan), atau `failed` (dengan kode error).
   - `handed_off` **bukan** berarti sampai di kotak masuk. UI menulis "diserahkan ke layanan email", bukan "terkirim".
2. Bila `MAIL_MAILER` adalah `log`/`array` (tidak benar-benar mengirim) di luar environment `local`/`testing`:
   - readiness check `/health/ready` gagal;
   - endpoint undangan & lupa-password mengembalikan **503 `EMAIL_NOT_CONFIGURED`** tanpa membuat klaim pengiriman.
3. Pengiriman berjalan lewat antrean **tanpa retry otomatis** (`tries = 1`): token mentah hanya ada di payload job (terenkripsi), jadi mengulang diam-diam memperbesar jendela paparannya. Kegagalan menandai `failed` dan admin mengirim ulang, yang menerbitkan token baru dan mematikan token lama.
4. Isi email tidak memuat data siswa. Tautan berisi token acak; DB hanya menyimpan hash-nya.

## Kebutuhan layanan email production (untuk dipilih nanti)
| Kebutuhan | Detail |
|---|---|
| Autentikasi domain | SPF, DKIM, DMARC pada domain pengirim |
| Antarmuka | SMTP terenkripsi (TLS) atau API yang didukung Laravel mailer |
| Pelaporan | Webhook bounce/complaint agar `delivery_status` dapat diperbarui |
| Perlindungan data | Perjanjian pemrosesan data; lokasi pemrosesan dicatat untuk kajian PDP |
| Volume | Rendah (undangan & reset). Batas laju sesuai paket. |
| Secret | Kredensial di secret manager, tidak di repo |

## Pengujian
| Kasus | Harapan |
|---|---|
| Undangan dibuat (mailer fake) | Email ber-tautan ter-antre; `delivery_status` berubah sesuai hasil transport |
| Transport melempar error | `failed`, bukan `handed_off` |
| Environment `production` dengan `MAIL_MAILER=log` | 503 `EMAIL_NOT_CONFIGURED`; readiness gagal |
| Isi email | Tidak memuat token mentah lain selain tautan; tidak ada data siswa |
