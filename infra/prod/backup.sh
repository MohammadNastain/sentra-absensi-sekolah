#!/bin/sh
# Cadangan harian produksi: pg_dump dari container postgres, simpan lokal (retensi 14 hari), lalu salin ke luar server bila
# BACKUP_REMOTE diisi (format tujuan rsync/scp, mis. pengguna@host:/folder/). Cadangan hanya di disk VPS BUKAN cadangan sungguhan.
# Jalankan dari folder infra/prod lewat cron:  15 2 * * * cd /opt/sentra/infra/prod && sh backup.sh >> /var/log/sentra-backup.log 2>&1
set -eu

OUT_DIR="${BACKUP_DIR:-/var/backups/sentra}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
FILE="$OUT_DIR/sentra-${STAMP}.dump"
umask 077
mkdir -p "$OUT_DIR"

docker compose exec -T postgres pg_dump --username postgres --format=custom --no-owner --no-privileges --dbname sentra > "$FILE"
[ -s "$FILE" ] || { echo "Cadangan kosong: $FILE" >&2; rm -f "$FILE"; exit 1; }
( cd "$OUT_DIR" && sha256sum "$(basename "$FILE")" > "$(basename "$FILE").sha256" )
echo "Cadangan: $FILE ($(wc -c < "$FILE") byte)"

find "$OUT_DIR" -name 'sentra-*.dump*' -mtime +"$KEEP_DAYS" -delete

if [ -n "${BACKUP_REMOTE:-}" ]; then
  rsync -a "$FILE" "$FILE.sha256" "$BACKUP_REMOTE"
  echo "Disalin ke $BACKUP_REMOTE"
else
  echo "PERINGATAN: BACKUP_REMOTE kosong; belum ada salinan di luar server." >&2
fi
