# apps/api — Laravel API Sentra Sekolah

Composer & artisan **hanya** dijalankan di container (ADR 0007 D-41). Jalankan dari root repo:

```bash
docker compose --profile api build api
docker compose run --rm --no-deps api composer install
docker compose run --rm --no-deps api sh -c "cp .env.example .env && php artisan key:generate"
docker compose run --rm api composer migrate        # migrasi sebagai sentra_owner
docker compose run --rm api php artisan test        # Pest di PostgreSQL (database sentra_test)
docker compose run --rm --no-deps api composer lint     # Pint
docker compose run --rm --no-deps api composer analyse  # Larastan level 8
```

- Runtime memakai role `sentra_app` (tanpa DDL, tunduk RLS). Jangan menjalankan `php artisan migrate` tanpa `--database=pgsql_owner`.
- Konteks RLS diatur lewat `App\Domain\Tenancy\DatabaseContext`. Lihat `docs/DATABASE_DESIGN.md` §6.
