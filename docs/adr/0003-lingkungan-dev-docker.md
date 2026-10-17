# ADR 0003 — Lingkungan Pengembangan dengan Docker Compose

- **Status:** Diterima (17 Okt 2026)
- **Konteks:** Mesin dev memiliki PHP 8.2 XAMPP tanpa ekstensi PostgreSQL, serta tidak memiliki Composer, PostgreSQL, maupun Docker. Pemilik produk memilih Docker Desktop.

## Keputusan
- `compose.yaml` di root menjalankan **PostgreSQL 18** dan **Redis 8.2** untuk dev. Service `php` (profile `api`) adalah image CLI PHP 8.5 + Composer yang dipakai mulai Tahap 1.
- Secret **tidak** ditulis di repo. `.env.example` hanya berisi penanda `__GENERATE__`, lalu `scripts/init-env.mjs` membuat `.env` (diabaikan git) berisi secret acak. Compose gagal dijalankan bila secret kosong (`${VAR:?}`).
- Port hanya di-bind ke `127.0.0.1`.
- Init script PostgreSQL membuat role terpisah sejak awal:
  - `sentra_owner`: pemilik skema, untuk migrasi.
  - `sentra_app`: runtime, `NOSUPERUSER NOBYPASSRLS`, tanpa hak DDL.
  Database `sentra` dan `sentra_test` dibuat dengan ekstensi `citext`, `pg_trgm`, `btree_gist`. Dengan begitu RLS dan aturan append-only bisa diuji dengan hak akses yang realistis sejak Tahap 1.
- Container PHP berjalan sebagai user non-root `app`.
- `scripts/verify-dev-env.sh` memverifikasi semua properti di atas dan dijalankan di CI (job `dev-environment`).

## Konsekuensi
- Init script hanya berjalan saat volume data kosong. Mengubah password atau role membutuhkan `docker compose down -v`.
- Konfigurasi ini **bukan** konfigurasi produksi (tanpa TLS, backup, maupun hardening). Produksi dirancang di Tahap 6 setelah kajian hosting dan perlindungan data (D-23).
