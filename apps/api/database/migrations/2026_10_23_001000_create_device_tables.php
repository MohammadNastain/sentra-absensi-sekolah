<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Perangkat terminal gerbang (ARCHITECTURE §6.2, Tahap 3B). Pairing memakai kode sekali pakai yang hanya
 * disimpan sebagai hash; hasilnya token perangkat 256-bit yang juga hanya disimpan sebagai hash. Terminal belum
 * punya konteks sekolah saat pairing dan saat memuat token, jadi pencarian lintas sekolah dibatasi pada dua
 * fungsi SECURITY DEFINER (klaim pairing, cari token) — pola yang sama dengan undangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE schools ADD COLUMN device_limit smallint NOT NULL DEFAULT 1;
            ALTER TABLE schools ADD CONSTRAINT schools_device_limit_check CHECK (device_limit BETWEEN 1 AND 50);

            CREATE TABLE devices (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                name text NOT NULL,
                device_type text NOT NULL DEFAULT 'gate_terminal',
                status text NOT NULL DEFAULT 'pending_pairing',
                token_hash bytea NULL,
                pairing_code_hash bytea NULL,
                pairing_expires_at timestamptz NULL,
                paired_at timestamptz NULL,
                last_seen_at timestamptz NULL,
                revoked_at timestamptz NULL,
                revoked_reason text NULL,
                reader_profile text NULL,
                created_by_user_id uuid NOT NULL REFERENCES users (id),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT devices_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT devices_token_hash_unique UNIQUE (token_hash),
                CONSTRAINT devices_name_not_blank CHECK (btrim(name) <> ''),
                CONSTRAINT devices_type_check CHECK (device_type IN ('gate_terminal')),
                CONSTRAINT devices_status_check CHECK (status IN ('pending_pairing', 'active', 'revoked')),
                CONSTRAINT devices_state_check CHECK (
                    (status = 'pending_pairing' AND token_hash IS NULL AND pairing_code_hash IS NOT NULL AND pairing_expires_at IS NOT NULL)
                    OR (status = 'active' AND token_hash IS NOT NULL AND paired_at IS NOT NULL AND pairing_code_hash IS NULL)
                    OR (status = 'revoked' AND revoked_at IS NOT NULL AND pairing_code_hash IS NULL)
                )
            );
            CREATE INDEX devices_school_status_index ON devices (school_id, status);
            CREATE UNIQUE INDEX devices_pairing_code_unique ON devices (pairing_code_hash) WHERE pairing_code_hash IS NOT NULL;

            ALTER TABLE devices ENABLE ROW LEVEL SECURITY;
            ALTER TABLE devices FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON devices
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());
            -- Hanya untuk dua fungsi SECURITY DEFINER di bawah (berjalan sebagai sentra_owner).
            CREATE POLICY device_lookup_owner_read ON devices
                FOR SELECT TO sentra_owner
                USING (true);
            CREATE POLICY device_claim_owner_update ON devices
                FOR UPDATE TO sentra_owner
                USING (status = 'pending_pairing') WITH CHECK (true);

            CREATE OR REPLACE FUNCTION find_device_by_token_hash(p_token_hash bytea)
                RETURNS TABLE (id uuid, school_id uuid, status text)
                LANGUAGE sql STABLE SECURITY DEFINER
                SET search_path = public, pg_temp
                AS $$
                    SELECT d.id, d.school_id, d.status FROM devices d WHERE d.token_hash = p_token_hash LIMIT 1
                $$;
            REVOKE ALL ON FUNCTION find_device_by_token_hash(bytea) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION find_device_by_token_hash(bytea) TO sentra_app;

            -- Klaim atomik: kode cocok, belum kedaluwarsa, perangkat masih menunggu pairing. Sekali pakai.
            CREATE OR REPLACE FUNCTION claim_device_pairing(p_code_hash bytea, p_token_hash bytea)
                RETURNS TABLE (id uuid, school_id uuid)
                LANGUAGE sql VOLATILE SECURITY DEFINER
                SET search_path = public, pg_temp
                AS $$
                    UPDATE devices d
                       SET status = 'active', token_hash = p_token_hash, paired_at = now(), last_seen_at = now(),
                           pairing_code_hash = NULL, pairing_expires_at = NULL, updated_at = now()
                     WHERE d.pairing_code_hash = p_code_hash
                       AND d.status = 'pending_pairing'
                       AND d.pairing_expires_at > now()
                    RETURNING d.id, d.school_id
                $$;
            REVOKE ALL ON FUNCTION claim_device_pairing(bytea, bytea) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION claim_device_pairing(bytea, bytea) TO sentra_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION claim_device_pairing(bytea, bytea);
            DROP FUNCTION find_device_by_token_hash(bytea);
            DROP TABLE devices;
            ALTER TABLE schools DROP COLUMN device_limit;
            SQL);
    }
};
