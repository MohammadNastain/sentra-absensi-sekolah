<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kredensial siswa (DATABASE_DESIGN §3.4, Tahap 3A): QR sekarang, RFID menyusul di Tahap 4. Nilai mentah
 * tidak pernah disimpan; hanya HMAC-SHA256 berversi kunci. Status hanya bergerak maju
 * (active ⇄ blocked → revoked); kartu yang diblokir tetap dikenali agar terminal dapat menjelaskan penolakannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE student_credentials (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                student_id uuid NOT NULL,
                credential_type text NOT NULL,
                value_hash bytea NOT NULL,
                hash_key_version smallint NOT NULL,
                value_hint text NULL,
                reader_profile text NULL,
                status text NOT NULL DEFAULT 'active',
                status_reason text NULL,
                issued_at timestamptz NOT NULL DEFAULT now(),
                blocked_at timestamptz NULL,
                revoked_at timestamptz NULL,
                replaces_credential_id uuid NULL,
                created_by_user_id uuid NOT NULL REFERENCES users (id),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT student_credentials_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT student_credentials_student_fk FOREIGN KEY (school_id, student_id)
                    REFERENCES students (school_id, id),
                CONSTRAINT student_credentials_replaces_fk FOREIGN KEY (school_id, replaces_credential_id)
                    REFERENCES student_credentials (school_id, id),
                CONSTRAINT student_credentials_type_check CHECK (credential_type IN ('qr', 'rfid')),
                CONSTRAINT student_credentials_status_check CHECK (status IN ('active', 'blocked', 'revoked')),
                CONSTRAINT student_credentials_hash_length CHECK (octet_length(value_hash) = 32),
                CONSTRAINT student_credentials_status_timestamps CHECK (
                    (status <> 'revoked' OR revoked_at IS NOT NULL)
                    AND (status <> 'blocked' OR blocked_at IS NOT NULL)
                )
            );
            CREATE UNIQUE INDEX student_credentials_value_unique
                ON student_credentials (school_id, credential_type, value_hash) WHERE status <> 'revoked';
            CREATE UNIQUE INDEX student_credentials_one_active
                ON student_credentials (school_id, student_id, credential_type) WHERE status = 'active';
            CREATE INDEX student_credentials_student_index
                ON student_credentials (school_id, student_id, issued_at DESC);

            ALTER TABLE student_credentials ENABLE ROW LEVEL SECURITY;
            ALTER TABLE student_credentials FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON student_credentials
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE student_credentials;');
    }
};
