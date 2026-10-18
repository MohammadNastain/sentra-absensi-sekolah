# ADR 0002 — Versi Dependency

- **Status:** Diterima (17 Okt 2026). Ditinjau ulang setiap awal tahap.
- **Kebijakan:**
  - Pakai versi stabil (bukan RC/beta) yang masih dalam masa dukungan aktif.
  - Versi baru dipakai setelah dirilis **minimal ±2 minggu**, kecuali untuk patch keamanan.
  - Semua versi dipin secara eksak (tag image, `composer.lock`, `pnpm-lock.yaml`).

Versi diperiksa langsung ke Docker Hub, Packagist, npm, dan GitHub Releases pada 17 Okt 2026.

| Komponen | Versi dipin | Dipakai sejak | Alasan / catatan |
|---|---|---|---|
| PHP | **8.5.11** (`php:8.5.11-cli-alpine3.23`) | Tahap 0 | Dukungan aktif s/d 31 Des 2027. PHP 8.4 dukungan aktifnya berakhir 31 Des 2026. Laravel 13 mensyaratkan PHP ^8.3. |
| Laravel | **13.34.0** (`~13.34.0`, rilis 29 Sep 2026) | Tahap 1 | 13.35–13.37 berumur < 2 minggu saat scaffold (17 Okt 2026). |
| Laravel Fortify | **1.41.0** (28 Sep 2026) | Tahap 1 | ADR 0007 D-38. Ikut menarik `laravel/passkeys` sebagai dependency; fitur passkey **tidak** diaktifkan. |
| Laravel Sanctum | **4.3.3** (`^4.3.3`) | Tahap 1 | Versi pertama yang mendukung `illuminate/* ^13`. |
| predis | **3.6.1** (`~3.6.1`) | Tahap 1 | ADR 0007 D-40. 3.7.0 berumur 9 hari. |
| Pest | **5.2.1** (`~5.2.1`) + pest-plugin-laravel 5.0.1 | Tahap 1 | Menarik PHPUnit 13.3.4. Pest 5.3.x berumur < 2 minggu. |
| Larastan | **3.12.3** (`~3.12.2`) + PHPStan 2.3.1 | Tahap 1 | Patch 3.12.3 (13 hari) diambil Composer; level 8 untuk `app/`, `database/`, `routes/`. |
| OpenSpout | **5.12.0** (24 Sep 2026) | Tahap 2C | Pembaca CSV/XLSX streaming untuk impor siswa. 5.12.1–5.12.2 (12–13 Okt) berumur < 2 minggu. |
| Composer | **2.10.3** | Tahap 0 | |
| docker-php-extension-installer | **2.12.0** (24 Sep 2026) | Tahap 0 | 2.12.1 baru berumur 2 hari, jadi belum dipakai. |
| PostgreSQL | **18.6** (`postgres:18.6-alpine3.23`) | Tahap 0 | Major terbaru. Volume di `/var/lib/postgresql` sesuai perubahan image 18+. |
| Redis | **8.2.10** (`redis:8.2.10-alpine3.22`) | Tahap 0 | Cabang 8.x dengan dukungan terpanjang (EOL Sep 2030 menurut endoflife.date). Lisensi Redis 8 (RSALv2/SSPLv1/AGPLv3) aman untuk dev; untuk produksi dievaluasi di Tahap 6 bersama alternatif Valkey (BSD). |
| Node.js | **24 LTS** (host 24.22.0) | Tahap 0 | Node 26 menjadi LTS pada 28 Okt 2026; upgrade dievaluasi setelah stabil. |
| pnpm | **12.8.2** (30 Sep 2026) | Tahap 1 | Dipin via `packageManager` (corepack). |
| Next.js | **16.x** (terbaru 16.4.2) | Tahap 1 | Patch dipilih saat scaffold sesuai kebijakan 2 minggu. |
| TypeScript | **diputuskan di Tahap 1** | Tahap 1 | Rilis terbaru 7.0.x (compiler native). Kompatibilitasnya dengan Next.js 16, ESLint, dan tooling test **belum diverifikasi**. Fallback ke 6.x/5.9 bila belum kompatibel. |
| gitleaks | **8.30.1** | Tahap 0 (CI) | |
| actions/checkout | **v7** | Tahap 0 (CI) | Pin ke commit SHA dipertimbangkan bersama Dependabot. |

## Belum dipasang (sengaja)
- **Object storage dev:** status distribusi image Docker MinIO berubah pada 2025. Alternatif (mis. Garage, SeaweedFS) dievaluasi di Tahap 2 saat fitur foto/impor membutuhkannya.
- **Mailpit:** ditambahkan di Tahap 1 bila alur undangan/reset via email disetujui (lihat DECISIONS D-37).
