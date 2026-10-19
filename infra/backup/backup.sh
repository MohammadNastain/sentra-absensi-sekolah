#!/bin/sh
# Cadangan logis database Sentra (pg_dump format custom, terkompresi) + checksum.
# Dev:        bash infra/backup/backup.sh [direktori-tujuan]
# Produksi:   jalankan dari cron/systemd pada host yang dapat menjangkau PostgreSQL; set PGHOST/PGUSER/PGPASSWORD/DB_NAME.
#             Cadangan logis BUKAN pengganti PITR (WAL archiving); keduanya dipakai bersama (lihat docs/ops/BACKUP_RESTORE.md).
set -eu

DB_NAME="${DB_NAME:-sentra}"
OUT_DIR="${1:-backups}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
FILE="$OUT_DIR/${DB_NAME}-${STAMP}.dump"
mkdir -p "$OUT_DIR"

if [ -n "${PGHOST:-}" ]; then
  pg_dump --format=custom --no-owner --no-privileges --dbname "$DB_NAME" > "$FILE"
else
  # Dev: lewat container postgres sebagai superuser.
  docker compose exec -T postgres pg_dump --username postgres --format=custom --no-owner --no-privileges --dbname "$DB_NAME" > "$FILE"
fi

[ -s "$FILE" ] || { echo "Cadangan kosong: $FILE" >&2; rm -f "$FILE"; exit 1; }
( cd "$OUT_DIR" && sha256sum "$(basename "$FILE")" > "$(basename "$FILE").sha256" )
echo "Cadangan: $FILE ($(wc -c < "$FILE") byte)"
