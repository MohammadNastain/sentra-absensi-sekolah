# Sentra Absensi Sekolah

SaaS multi-tenant untuk absensi siswa (QR Code, RFID, dan alur bantuan petugas). Kebutuhan produk ada di [`PRD_Sentra_Absensi_Sekolah_v1.md`](PRD_Sentra_Absensi_Sekolah_v1.md).

> **Status: Tahap 0 (fondasi repository).** Belum ada fitur aplikasi. Belum ada integrasi hardware yang diuji. Belum ada konfigurasi produksi.

## Struktur monorepo

```text
.
├── apps/
│   ├── api/          # Laravel 13 (PHP 8.5) — dibuat di Tahap 1
│   ├── web/          # Next.js dashboard — dibuat di Tahap 1
│   └── terminal/     # Next.js kiosk gerbang — dibuat di Tahap 3
├── packages/         # kode bersama TypeScript (api-client, ui) — Tahap 1+
├── infra/docker/     # image & init script untuk lingkungan dev
├── scripts/          # init-env, verify-dev-env
├── docs/             # arsitektur, database, rencana, ADR
├── compose.yaml      # Docker Compose (DEV)
└── .github/workflows # CI
```

## Memulai (pengembangan lokal)

Prasyarat: **Docker Desktop** (dengan WSL 2 di Windows), **Node.js 24**, **Git** (Git Bash). Panduan instalasi: [`docs/DEV_SETUP.md`](docs/DEV_SETUP.md).

```bash
node scripts/init-env.mjs          # membuat .env berisi secret acak (sekali saja)
docker compose up -d --wait        # PostgreSQL 18 + Redis 8.2
bash scripts/verify-dev-env.sh     # verifikasi otomatis lingkungan
```

File `.env` tidak pernah di-commit. Jangan menyalin secret ke file lain yang dilacak git.

## Dokumentasi

| Dokumen | Isi |
|---|---|
| [ARCHITECTURE](docs/ARCHITECTURE.md) | Arsitektur, auth, otorisasi, isolasi tenant, API |
| [DATABASE_DESIGN](docs/DATABASE_DESIGN.md) | ERD, tabel, constraint, RLS, migrasi |
| [IMPLEMENTATION_PLAN](docs/IMPLEMENTATION_PLAN.md) | Tahapan, dependensi, acceptance criteria |
| [TESTING_STRATEGY](docs/TESTING_STRATEGY.md) | Strategi pengujian |
| [DECISIONS](docs/DECISIONS.md) | Keputusan terbuka & risiko |
| [TAHAP_1_FONDASI](docs/TAHAP_1_FONDASI.md) | Spesifikasi rinci Tahap 1 |
| [adr/](docs/adr/) | Catatan keputusan arsitektur |
| [hardware/](docs/hardware/) | Abstraksi perangkat & protokol uji kompatibilitas |
