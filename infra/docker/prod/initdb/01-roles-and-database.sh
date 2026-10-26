#!/bin/sh
# Dijalankan SEKALI oleh image postgres saat volume data masih kosong.
# Membuat dua role terpisah (lihat docs/DATABASE_DESIGN.md §5):
#   sentra_owner — pemilik skema, dipakai migrasi
#   sentra_app   — runtime aplikasi: NOSUPERUSER, NOBYPASSRLS, tanpa hak DDL
# serta database `sentra` (produksi; varian dari infra/docker/postgres/initdb tanpa sentra_test).
set -eu

: "${SENTRA_DB_OWNER_PASSWORD:?SENTRA_DB_OWNER_PASSWORD wajib diisi}"
: "${SENTRA_DB_APP_PASSWORD:?SENTRA_DB_APP_PASSWORD wajib diisi}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
  -v owner_pw="$SENTRA_DB_OWNER_PASSWORD" \
  -v app_pw="$SENTRA_DB_APP_PASSWORD" <<'SQL'
CREATE ROLE sentra_owner LOGIN NOSUPERUSER NOCREATEROLE NOBYPASSRLS CREATEDB PASSWORD :'owner_pw';
CREATE ROLE sentra_app   LOGIN NOSUPERUSER NOCREATEROLE NOBYPASSRLS NOCREATEDB PASSWORD :'app_pw';

CREATE DATABASE sentra OWNER sentra_owner;
SQL

for db in sentra; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" -v db="$db" <<'SQL'
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE :"db" TO sentra_app;

ALTER SCHEMA public OWNER TO sentra_owner;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO sentra_app;

CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gist;

-- Hak default untuk objek yang dibuat sentra_owner lewat migrasi.
-- Tabel append-only (attendance_events, audit_logs) akan dicabut UPDATE/DELETE-nya di migrasi.
ALTER DEFAULT PRIVILEGES FOR ROLE sentra_owner IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO sentra_app;
ALTER DEFAULT PRIVILEGES FOR ROLE sentra_owner IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO sentra_app;
ALTER DEFAULT PRIVILEGES FOR ROLE sentra_owner IN SCHEMA public
  GRANT EXECUTE ON FUNCTIONS TO sentra_app;
SQL
done
