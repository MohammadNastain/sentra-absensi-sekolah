<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit log append-only (DATABASE_DESIGN §3.6, ARCHITECTURE §12).
 *
 * - sentra_app hanya boleh INSERT & SELECT; trigger menolak UPDATE/DELETE/TRUNCATE dari role mana pun.
 * - Baris sekolah terlihat hanya dalam konteks sekolahnya.
 * - Baris tanpa sekolah (aksi platform & autentikasi) hanya terlihat dalam konteks platform,
 *   tetapi boleh ditulis dari konteks mana pun (mis. login gagal sebelum ada konteks).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE audit_logs (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NULL REFERENCES schools (id),
                actor_type text NOT NULL,
                actor_user_id uuid NULL REFERENCES users (id),
                actor_device_id uuid NULL,
                action text NOT NULL,
                entity_type text NULL,
                entity_id uuid NULL,
                reason text NULL,
                before_value jsonb NULL,
                after_value jsonb NULL,
                ip_address inet NULL,
                user_agent text NULL,
                request_id text NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT audit_logs_actor_type_check CHECK (actor_type IN ('user', 'device', 'system')),
                CONSTRAINT audit_logs_actor_user_check CHECK (actor_type <> 'user' OR actor_user_id IS NOT NULL),
                CONSTRAINT audit_logs_actor_device_check CHECK (actor_type <> 'device' OR actor_device_id IS NOT NULL),
                CONSTRAINT audit_logs_action_format CHECK (action ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$')
            );
            CREATE INDEX audit_logs_school_created_index ON audit_logs (school_id, created_at DESC);
            CREATE INDEX audit_logs_school_entity_index ON audit_logs (school_id, entity_type, entity_id);

            CREATE TRIGGER audit_logs_append_only
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation();
            CREATE TRIGGER audit_logs_no_truncate
                BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION reject_append_only_mutation();

            REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM sentra_app;

            ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;
            ALTER TABLE audit_logs FORCE ROW LEVEL SECURITY;
            CREATE POLICY audit_read ON audit_logs
                FOR SELECT
                USING (
                    (school_id IS NOT NULL AND school_id = app_school_id())
                    OR (school_id IS NULL AND app_is_platform())
                );
            CREATE POLICY audit_insert ON audit_logs
                FOR INSERT
                WITH CHECK (school_id IS NULL OR school_id = app_school_id());
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE audit_logs');
    }
};
