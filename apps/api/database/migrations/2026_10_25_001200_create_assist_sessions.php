<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sesi bantuan petugas di terminal (ARCHITECTURE §6.3): berumur pendek (idle 10 menit, maksimum 30 menit),
 * terikat ke satu perangkat dan satu petugas, hanya hash token yang disimpan. Kode alasan bantuan di event
 * dibatasi enum (catatan wajib untuk `other` ditegakkan di aplikasi).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE assist_sessions (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                device_id uuid NOT NULL,
                user_id uuid NOT NULL REFERENCES users (id),
                token_hash bytea NOT NULL,
                started_at timestamptz NOT NULL DEFAULT now(),
                last_activity_at timestamptz NOT NULL DEFAULT now(),
                expires_at timestamptz NOT NULL,
                ended_at timestamptz NULL,
                ended_reason text NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT assist_sessions_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT assist_sessions_token_hash_unique UNIQUE (token_hash),
                CONSTRAINT assist_sessions_device_fk FOREIGN KEY (school_id, device_id) REFERENCES devices (school_id, id),
                CONSTRAINT assist_sessions_token_length CHECK (octet_length(token_hash) = 32),
                CONSTRAINT assist_sessions_ended_reason_check CHECK (ended_reason IS NULL OR ended_reason IN ('logout', 'idle', 'expired', 'device_revoked', 'replaced')),
                CONSTRAINT assist_sessions_end_consistent CHECK ((ended_at IS NULL) = (ended_reason IS NULL))
            );
            CREATE INDEX assist_sessions_active_index ON assist_sessions (school_id, device_id) WHERE ended_at IS NULL;

            ALTER TABLE assist_sessions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE assist_sessions FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON assist_sessions
                USING (school_id = app_school_id()) WITH CHECK (school_id = app_school_id());

            ALTER TABLE attendance_events ADD CONSTRAINT attendance_events_assist_reason_check
                CHECK (assist_reason_code IS NULL OR assist_reason_code IN ('card_forgotten', 'card_damaged', 'early_leave', 'other'));
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE attendance_events DROP CONSTRAINT attendance_events_assist_reason_check;
            DROP TABLE assist_sessions;
            SQL);
    }
};
