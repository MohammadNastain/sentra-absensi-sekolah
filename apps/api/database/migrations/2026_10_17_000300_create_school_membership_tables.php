<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sekolah, keanggotaan, peran, dan undangan (TAHAP_1_FONDASI §2, ADR 0004).
 *
 * RLS:
 * - schools & roles tidak memakai RLS (akses diatur aplikasi; roles hanya-baca bagi sentra_app).
 * - school_memberships & membership_roles: baca dalam konteks sekolah ATAU milik user konteks;
 *   tulis hanya dalam konteks sekolah.
 * - membership_invitations: konteks sekolah; user konteks dapat membaca undangan ke email-nya.
 *   Lookup token publik lewat fungsi SECURITY DEFINER milik sentra_owner yang hanya
 *   mengembalikan satu baris berdasarkan hash token.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE schools (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                code citext NOT NULL,
                npsn text NULL,
                name text NOT NULL,
                education_levels text[] NOT NULL,
                timezone text NOT NULL,
                address text NULL,
                status text NOT NULL DEFAULT 'active',
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT schools_code_unique UNIQUE (code),
                CONSTRAINT schools_npsn_unique UNIQUE (npsn),
                CONSTRAINT schools_code_format CHECK (code ~ '^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$'),
                CONSTRAINT schools_npsn_format CHECK (npsn IS NULL OR npsn ~ '^[0-9]{8}$'),
                CONSTRAINT schools_name_not_blank CHECK (btrim(name) <> ''),
                CONSTRAINT schools_education_levels_check CHECK (
                    cardinality(education_levels) >= 1
                    AND education_levels <@ ARRAY['SD', 'MI', 'SMP', 'MTs', 'SMA', 'SMK', 'MA', 'lainnya']::text[]
                ),
                CONSTRAINT schools_timezone_check CHECK (timezone IN ('Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura')),
                CONSTRAINT schools_status_check CHECK (status IN ('trial', 'active', 'suspended', 'terminated'))
            );

            CREATE TABLE roles (
                code text PRIMARY KEY,
                name text NOT NULL
            );

            INSERT INTO roles (code, name) VALUES
                ('school_admin', 'Admin Sekolah'),
                ('principal', 'Kepala Sekolah'),
                ('homeroom_teacher', 'Wali Kelas'),
                ('duty_officer', 'Petugas Piket'),
                ('terminal_operator', 'Operator Terminal');

            REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON roles FROM sentra_app;

            CREATE TABLE school_memberships (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                user_id uuid NOT NULL REFERENCES users (id),
                status text NOT NULL DEFAULT 'active',
                status_reason text NULL,
                joined_at timestamptz NOT NULL DEFAULT now(),
                ended_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT school_memberships_school_user_unique UNIQUE (school_id, user_id),
                CONSTRAINT school_memberships_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT school_memberships_status_check CHECK (status IN ('active', 'suspended', 'ended')),
                CONSTRAINT school_memberships_ended_at_check CHECK ((status = 'ended') = (ended_at IS NOT NULL))
            );
            CREATE INDEX school_memberships_user_id_index ON school_memberships (user_id);

            CREATE TABLE membership_roles (
                school_id uuid NOT NULL,
                membership_id uuid NOT NULL,
                role_code text NOT NULL REFERENCES roles (code),
                granted_by_user_id uuid NULL REFERENCES users (id),
                granted_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (membership_id, role_code),
                CONSTRAINT membership_roles_membership_fk FOREIGN KEY (school_id, membership_id)
                    REFERENCES school_memberships (school_id, id) ON DELETE CASCADE
            );
            CREATE INDEX membership_roles_school_role_index ON membership_roles (school_id, role_code);

            CREATE TABLE membership_invitations (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                email citext NOT NULL,
                role_codes text[] NOT NULL,
                token_hash bytea NOT NULL,
                invited_by_user_id uuid NOT NULL REFERENCES users (id),
                expires_at timestamptz NOT NULL,
                accepted_at timestamptz NULL,
                accepted_user_id uuid NULL REFERENCES users (id),
                revoked_at timestamptz NULL,
                -- ADR 0008: handed_off = diterima transport, BUKAN berarti sampai di kotak masuk.
                delivery_status text NOT NULL DEFAULT 'queued',
                delivery_error text NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT membership_invitations_token_hash_unique UNIQUE (token_hash),
                CONSTRAINT membership_invitations_delivery_status_check
                    CHECK (delivery_status IN ('queued', 'handed_off', 'failed')),
                CONSTRAINT membership_invitations_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT membership_invitations_token_hash_length CHECK (octet_length(token_hash) = 32),
                CONSTRAINT membership_invitations_role_codes_check CHECK (
                    cardinality(role_codes) >= 1
                    AND role_codes <@ ARRAY['school_admin', 'principal', 'homeroom_teacher', 'duty_officer', 'terminal_operator']::text[]
                ),
                CONSTRAINT membership_invitations_accepted_check
                    CHECK ((accepted_at IS NULL) = (accepted_user_id IS NULL)),
                CONSTRAINT membership_invitations_final_state_check
                    CHECK (accepted_at IS NULL OR revoked_at IS NULL)
            );
            CREATE UNIQUE INDEX membership_invitations_pending_unique
                ON membership_invitations (school_id, email)
                WHERE accepted_at IS NULL AND revoked_at IS NULL;
            CREATE INDEX membership_invitations_email_index ON membership_invitations (email);

            -- RLS
            ALTER TABLE school_memberships ENABLE ROW LEVEL SECURITY;
            ALTER TABLE school_memberships FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON school_memberships
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());
            CREATE POLICY own_memberships_read ON school_memberships
                FOR SELECT
                USING (user_id = app_user_id());

            ALTER TABLE membership_roles ENABLE ROW LEVEL SECURITY;
            ALTER TABLE membership_roles FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON membership_roles
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());
            CREATE POLICY own_membership_roles_read ON membership_roles
                FOR SELECT
                USING (EXISTS (
                    SELECT 1 FROM school_memberships m
                    WHERE m.id = membership_roles.membership_id
                      AND m.user_id = app_user_id()
                ));

            ALTER TABLE membership_invitations ENABLE ROW LEVEL SECURITY;
            ALTER TABLE membership_invitations FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON membership_invitations
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());
            CREATE POLICY own_invitations_read ON membership_invitations
                FOR SELECT
                USING (email = (SELECT u.email FROM users u WHERE u.id = app_user_id()));
            -- Hanya untuk fungsi SECURITY DEFINER di bawah (berjalan sebagai sentra_owner).
            CREATE POLICY token_lookup_owner_read ON membership_invitations
                FOR SELECT
                TO sentra_owner
                USING (true);

            CREATE OR REPLACE FUNCTION find_invitation_by_token_hash(p_token_hash bytea)
                RETURNS SETOF membership_invitations
                LANGUAGE sql STABLE SECURITY DEFINER
                SET search_path = public, pg_temp
                AS $$
                    SELECT * FROM membership_invitations WHERE token_hash = p_token_hash LIMIT 1
                $$;
            REVOKE ALL ON FUNCTION find_invitation_by_token_hash(bytea) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION find_invitation_by_token_hash(bytea) TO sentra_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION find_invitation_by_token_hash(bytea);
            DROP TABLE membership_invitations;
            DROP TABLE membership_roles;
            DROP TABLE school_memberships;
            DROP TABLE roles;
            DROP TABLE schools;
            SQL);
    }
};
