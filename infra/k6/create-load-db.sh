#!/bin/sh
# Membuat database khusus uji beban `sentra_load` (event bersifat append-only, jadi jangan memakai `sentra`).
# Jalankan dari root repo:  bash infra/k6/create-load-db.sh
set -eu
PG="docker compose exec -T postgres psql -v ON_ERROR_STOP=1 --username postgres"

$PG --dbname postgres -c "DROP DATABASE IF EXISTS sentra_load" -c "CREATE DATABASE sentra_load OWNER sentra_owner"
$PG --dbname sentra_load <<'SQL'
REVOKE ALL ON DATABASE sentra_load FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE sentra_load TO sentra_app;
ALTER SCHEMA public OWNER TO sentra_owner;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO sentra_app;
CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gist;
ALTER DEFAULT PRIVILEGES FOR ROLE sentra_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO sentra_app;
ALTER DEFAULT PRIVILEGES FOR ROLE sentra_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO sentra_app;
ALTER DEFAULT PRIVILEGES FOR ROLE sentra_owner IN SCHEMA public GRANT EXECUTE ON FUNCTIONS TO sentra_app;
SQL
echo "sentra_load siap. Lanjut: docker compose exec -e DB_DATABASE=sentra_load api php artisan migrate --database=pgsql_owner --force"
