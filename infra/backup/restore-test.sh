#!/bin/sh
# Uji restore: memulihkan berkas cadangan ke database sementara dan membandingkan jumlah baris tabel kunci dengan
# sumber, lalu memeriksa bahwa RLS dan trigger append-only ikut pulih. Hanya untuk dev/staging (memakai container postgres).
# Pakai:  bash infra/backup/restore-test.sh backups/sentra-XXXX.dump
set -eu

FILE="${1:?berikan berkas .dump}"
SRC="${DB_NAME:-sentra}"
TMP="sentra_restore_test"
PG="docker compose exec -T postgres psql -v ON_ERROR_STOP=1 --username postgres --tuples-only --no-align"

[ -f "$FILE.sha256" ] && ( cd "$(dirname "$FILE")" && sha256sum -c "$(basename "$FILE").sha256" )

$PG --dbname postgres -c "DROP DATABASE IF EXISTS $TMP" -c "CREATE DATABASE $TMP OWNER sentra_owner" >/dev/null
docker compose exec -T postgres pg_restore --username postgres --dbname "$TMP" --no-owner --role sentra_owner --exit-on-error < "$FILE"

status=0
for t in schools users students daily_attendance attendance_events attendance_adjustments audit_logs leave_requests correction_requests; do
  a="$($PG --dbname "$SRC" -c "SELECT count(*) FROM $t")"
  b="$($PG --dbname "$TMP" -c "SELECT count(*) FROM $t")"
  if [ "$a" = "$b" ]; then echo "OK    $t: $a baris"; else echo "BEDA  $t: sumber=$a pulih=$b"; status=1; fi
done

rls="$($PG --dbname "$TMP" -c "SELECT count(*) FROM pg_class WHERE relrowsecurity AND relforcerowsecurity AND relkind='r'")"
rls_src="$($PG --dbname "$SRC" -c "SELECT count(*) FROM pg_class WHERE relrowsecurity AND relforcerowsecurity AND relkind='r'")"
trg="$($PG --dbname "$TMP" -c "SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal")"
trg_src="$($PG --dbname "$SRC" -c "SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal")"
[ "$rls" = "$rls_src" ] && echo "OK    tabel dengan RLS dipaksa: $rls" || { echo "BEDA  RLS: sumber=$rls_src pulih=$rls"; status=1; }
[ "$trg" = "$trg_src" ] && echo "OK    trigger: $trg" || { echo "BEDA  trigger: sumber=$trg_src pulih=$trg"; status=1; }

$PG --dbname postgres -c "DROP DATABASE $TMP" >/dev/null
[ "$status" = 0 ] && echo "UJI RESTORE LULUS" || echo "UJI RESTORE GAGAL"
exit "$status"
