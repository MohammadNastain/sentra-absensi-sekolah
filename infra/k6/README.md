# Uji beban jam masuk (k6)

Skenario `docs/TESTING_STRATEGY.md` §8, target PRD 11.1: **p95 scan ≤ 2 dtk, dashboard ≤ 3 dtk, error < 0,1%, tanpa accepted ganda.**

- Tiap terminal memindai 1 kartu / 2 dtk (`RATE_SECONDS`); ±10% pemindaian ulang kartu yang sudah tercatat (duplikat).
- Satu admin per sekolah memoll `attendance/today` tiap 10 dtk seperti UI dashboard.
- Ekspor besar belum ada (laporan/ekspor = Tahap 5); tambahkan ke skenario saat endpoint-nya ada.

## Menjalankan (dari root repo)

Memakai database terpisah `sentra_load` karena event bersifat append-only dan tidak bisa dibersihkan.

```bash
bash infra/k6/create-load-db.sh                       # (ulang) buat sentra_load
docker compose --profile api run --rm -e DB_DATABASE=sentra_load api \
  php artisan migrate --database=pgsql_owner --force
docker compose --profile api run --rm -e DB_DATABASE=sentra_load api \
  php artisan loadtest:prepare --schools=3 --students=500 --devices=2    # S, siswa, terminal
# API terpisah untuk uji beban (port 8001, memakai sentra_load):
docker compose --profile api run -d --name sentra-load-api -p 127.0.0.1:8001:8000 \
  -e DB_DATABASE=sentra_load -e SESSION_DOMAIN=null -e PHP_CLI_SERVER_WORKERS=16 api
# k6 (image resmi, tanpa instalasi):
MSYS_NO_PATHCONV=1 docker run --rm --add-host=host.docker.internal:host-gateway \
  -v "$(pwd -W)/infra/k6:/scripts" -v "$(pwd -W)/apps/api/storage/app:/work" \
  -e BASE_URL=http://host.docker.internal:8001 -e DATA=/work/loadtest.json -e DURATION=30m \
  grafana/k6:latest run /scripts/jam-masuk.js
# Invarian setelah uji (semua harus 0 baris):
docker compose exec -T postgres psql -U postgres -d sentra_load < infra/k6/verify.sql
docker rm -f sentra-load-api
```

`loadtest.json` memuat token perangkat dan password admin uji; berkasnya di `storage/app` (tidak di-commit).
Jumlah kartu per perangkat = siswa / terminal; setelah semua kartu dipakai, iterasi berikutnya hanya duplikat.

## Catatan
- `SESSION_DOMAIN=null` perlu karena `.env` dev memakai `localhost`, sedangkan k6 mengakses lewat `host.docker.internal`.
- Server dev `php -S` menahan satu worker per koneksi keep-alive: `PHP_CLI_SERVER_WORKERS` harus ≥ jumlah VU
  (terminal + sekolah), kalau tidak muncul `dial: i/o timeout` yang bukan masalah aplikasi.
- Angka dari server dev + Docker Desktop hanya uji kewarasan skrip dan invarian, **bukan** bukti target PRD.
  Ukur ulang di lingkungan menyerupai produksi (PHP-FPM/Octane, PostgreSQL berbagi sumber daya seperti produksi) dengan
  S dan jumlah siswa yang ditetapkan pemilik produk.
