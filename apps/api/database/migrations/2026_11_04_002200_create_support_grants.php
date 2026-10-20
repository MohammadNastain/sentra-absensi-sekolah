<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Akses dukungan Super Admin ke satu sekolah (break-glass, D-21): berbatas waktu, beralasan, dan teraudit.
 * Tabel ini hanya terbaca dalam konteks platform; Super Admin tidak pernah menjadi anggota sekolah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE support_grants (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                user_id uuid NOT NULL REFERENCES users (id),
                reason text NOT NULL,
                expires_at timestamptz NOT NULL,
                ended_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT support_grants_reason_check CHECK (length(btrim(reason)) >= 10),
                CONSTRAINT support_grants_window_check CHECK (expires_at > created_at)
            );
            CREATE INDEX support_grants_lookup_index ON support_grants (user_id, school_id, expires_at DESC) WHERE ended_at IS NULL;

            ALTER TABLE support_grants ENABLE ROW LEVEL SECURITY;
            ALTER TABLE support_grants FORCE ROW LEVEL SECURITY;
            CREATE POLICY platform_only ON support_grants
                USING (app_is_platform()) WITH CHECK (app_is_platform());
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE support_grants;');
    }
};
