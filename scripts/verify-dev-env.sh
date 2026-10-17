#!/usr/bin/env bash
# Verifikasi lingkungan pengembangan Docker Compose.
# Dipakai secara lokal dan di CI. Keluar dengan kode != 0 bila ada pemeriksaan yang gagal.
#
#   bash scripts/verify-dev-env.sh            # PostgreSQL + Redis + image PHP
#   bash scripts/verify-dev-env.sh --no-php   # lewati build image PHP (lebih cepat)
set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

WITH_PHP=1
[[ "${1:-}" == "--no-php" ]] && WITH_PHP=0

PASS=0
FAIL=0
ok()   { PASS=$((PASS + 1)); printf '  [OK]   %s\n' "$1"; }
fail() { FAIL=$((FAIL + 1)); printf '  [GAGAL] %s\n' "$1"; [[ -n "${2:-}" ]] && printf '         %s\n' "$2"; }
check_eq() { # deskripsi, nilai_aktual, nilai_harapan
  if [[ "$2" == "$3" ]]; then ok "$1"; else fail "$1" "diharapkan '$3', didapat '$2'"; fi
}

echo "== Prasyarat"
if ! command -v docker >/dev/null 2>&1; then
  fail "docker tersedia di PATH" "Pasang Docker Desktop: lihat docs/DEV_SETUP.md"
  echo; echo "Hasil: $PASS lulus, $FAIL gagal"; exit 1
fi
ok "docker tersedia ($(docker --version))"
if docker compose version >/dev/null 2>&1; then ok "docker compose tersedia"; else fail "docker compose tersedia"; fi
if [[ -f .env ]]; then ok ".env ada"; else fail ".env ada" "jalankan: node scripts/init-env.mjs"; echo; echo "Hasil: $PASS lulus, $FAIL gagal"; exit 1; fi

set -a
# shellcheck disable=SC1091
. ./.env
set +a

if docker compose config --quiet; then ok "compose.yaml valid"; else fail "compose.yaml valid"; fi

echo "== Menjalankan postgres, redis, mailpit"
if docker compose up -d --wait postgres redis mailpit; then ok "service sehat (healthcheck)"; else fail "service sehat (healthcheck)"; fi

psql_su() { docker compose exec -T postgres psql -U postgres -d "${2:-sentra}" -tAX -v ON_ERROR_STOP=1 -c "$1" 2>&1; }
psql_as() { # role, password, db, sql
  docker compose exec -T -e PGPASSWORD="$2" postgres \
    psql -h 127.0.0.1 -U "$1" -d "$3" -tAX -v ON_ERROR_STOP=1 -c "$4" 2>&1
}

echo "== PostgreSQL"
ver=$(psql_su "SHOW server_version_num")
if [[ "$ver" =~ ^18[0-9]{4}$ ]]; then ok "PostgreSQL 18 ($ver)"; else fail "PostgreSQL 18" "server_version_num=$ver"; fi
check_eq "timezone server = UTC" "$(psql_su "SHOW timezone")" "UTC"
check_eq "encoding database sentra = UTF8" "$(psql_su "SELECT pg_encoding_to_char(encoding) FROM pg_database WHERE datname='sentra'")" "UTF8"
check_eq "database sentra & sentra_test ada" \
  "$(psql_su "SELECT count(*) FROM pg_database WHERE datname IN ('sentra','sentra_test')")" "2"
check_eq "role sentra_owner/sentra_app bukan superuser & tanpa BYPASSRLS" \
  "$(psql_su "SELECT count(*) FROM pg_roles WHERE rolname IN ('sentra_owner','sentra_app') AND NOT rolsuper AND NOT rolbypassrls")" "2"

for db in sentra sentra_test; do
  check_eq "ekstensi citext, pg_trgm, btree_gist di $db" \
    "$(psql_su "SELECT count(*) FROM pg_extension WHERE extname IN ('citext','pg_trgm','btree_gist')" "$db")" "3"
  check_eq "sentra_app dapat login ke $db" \
    "$(psql_as sentra_app "$SENTRA_DB_APP_PASSWORD" "$db" "SELECT current_user")" "sentra_app"
done

out=$(psql_as sentra_app "$SENTRA_DB_APP_PASSWORD" sentra "CREATE TABLE verify_should_fail (id int)")
if [[ "$out" == *"permission denied"* ]]; then ok "sentra_app TIDAK bisa membuat tabel (tanpa DDL)"; else fail "sentra_app TIDAK bisa membuat tabel" "$out"; fi

out=$(psql_as sentra_owner "$SENTRA_DB_OWNER_PASSWORD" sentra "BEGIN; CREATE TABLE verify_owner_ddl (id int); ROLLBACK;")
if [[ "$out" == *"ROLLBACK"* || -z "${out//[[:space:]]/}" ]]; then ok "sentra_owner bisa DDL (migrasi)"; else fail "sentra_owner bisa DDL" "$out"; fi

out=$(docker compose exec -T -e PGPASSWORD=wrong-password postgres psql -h 127.0.0.1 -U sentra_app -d sentra -c "SELECT 1" 2>&1)
if [[ "$out" == *"password authentication failed"* ]]; then ok "password salah ditolak"; else fail "password salah ditolak" "$out"; fi

echo "== Redis"
check_eq "redis PING dengan password" "$(docker compose exec -T redis redis-cli ping 2>&1 | tr -d '\r')" "PONG"
out=$(docker compose exec -T redis sh -c 'env -u REDISCLI_AUTH redis-cli ping' 2>&1)
if [[ "$out" == *"NOAUTH"* ]]; then ok "redis menolak koneksi tanpa password"; else fail "redis menolak koneksi tanpa password" "$out"; fi

echo "== Mailpit"
out=$(docker compose exec -T mailpit wget -qO- http://127.0.0.1:8025/api/v1/info 2>&1)
if [[ "$out" == *"Version"* ]]; then ok "Mailpit API merespons"; else fail "Mailpit API merespons" "$out"; fi

echo "== Port hanya di 127.0.0.1"
ports=$(docker compose ps --format '{{.Publishers}}' 2>&1)
if [[ "$ports" == *"0.0.0.0"* ]]; then fail "port tidak terbuka ke semua interface" "$ports"; else ok "port tidak terbuka ke semua interface"; fi

if [[ $WITH_PHP -eq 1 ]]; then
  echo "== Image PHP (build)"
  if docker compose --profile api build api; then ok "image sentra/php-dev:8.5 ter-build"; else fail "image sentra/php-dev:8.5 ter-build"; fi
  phpv=$(docker run --rm sentra/php-dev:8.5 php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>&1)
  check_eq "PHP 8.5" "$phpv" "8.5"
  mods=$(docker run --rm sentra/php-dev:8.5 php -m 2>&1)
  for ext in pdo_pgsql pgsql intl zip gd bcmath pcntl mbstring sodium openssl "Zend OPcache"; do
    if grep -qx "$ext" <<<"$(tr -d '\r' <<<"$mods")"; then ok "ekstensi PHP: $ext"; else fail "ekstensi PHP: $ext"; fi
  done
  cv=$(docker run --rm sentra/php-dev:8.5 composer --version 2>&1)
  if [[ "$cv" == *"Composer version 2.10.3"* ]]; then ok "Composer 2.10.3"; else fail "Composer 2.10.3" "$cv"; fi
  uid=$(docker run --rm sentra/php-dev:8.5 id -un 2>&1)
  check_eq "container PHP berjalan sebagai user non-root" "$uid" "app"
fi

echo
echo "Hasil: $PASS lulus, $FAIL gagal"
[[ $FAIL -eq 0 ]]
