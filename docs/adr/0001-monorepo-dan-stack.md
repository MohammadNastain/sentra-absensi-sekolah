# ADR 0001 — Monorepo dan Stack Teknologi

- **Status:** Diterima (17 Okt 2026, disetujui pemilik produk)
- **Konteks:** PRD §6.1 merekomendasikan Laravel, Next.js + TypeScript, dan PostgreSQL. Produk terdiri dari API, dashboard, dan terminal kiosk yang berbagi kontrak API.

## Keputusan
1. **Satu repository (monorepo)** berisi `apps/api` (Laravel), `apps/web` (dashboard Next.js), `apps/terminal` (kiosk Next.js), dan `packages/*` (kode TypeScript bersama).
2. **pnpm workspaces** untuk paket JavaScript. Laravel dikelola Composer secara terpisah di `apps/api` (tidak dipaksa masuk tooling JS).
3. **PostgreSQL** sebagai satu-satunya database, termasuk saat test. SQLite/MySQL tidak dipakai sebagai pengganti karena desain bergantung pada partial unique index, exclusion constraint, `citext`, dan Row-Level Security.
4. Terminal adalah aplikasi terpisah dari dashboard (origin, bundle, dan CSP sendiri). Lihat ARCHITECTURE §3.

## Alasan
- Perubahan kontrak API, tipe klien, dan UI dapat di-review dalam satu pull request.
- CI tunggal dapat menjalankan test end-to-end lintas aplikasi.
- Pemisahan `apps/*` tetap memungkinkan deploy independen.

## Konsekuensi
- CI harus memfilter job berdasarkan path agar tidak semua job berjalan pada setiap perubahan (diatur saat aplikasi pertama dibuat).
- Developer memerlukan Docker (PHP/PostgreSQL/Redis) dan Node.js di host.
