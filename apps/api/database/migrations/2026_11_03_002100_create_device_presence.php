<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jejak keberadaan terminal per menit (satu baris per perangkat per menit saat ada heartbeat/aktivitas). Dipakai
 * penilaian kesehatan hari (ADR 0005): terminal aktif yang hilang terlalu lama selama jendela masuk menahan aturan alpa.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE device_presence (
                school_id uuid NOT NULL REFERENCES schools (id),
                device_id uuid NOT NULL REFERENCES devices (id),
                minute_at timestamptz NOT NULL,
                PRIMARY KEY (device_id, minute_at)
            );
            CREATE INDEX device_presence_school_minute_index ON device_presence (school_id, minute_at);
            ALTER TABLE device_presence ENABLE ROW LEVEL SECURITY;
            ALTER TABLE device_presence FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON device_presence
                USING (school_id = app_school_id()) WITH CHECK (school_id = app_school_id());
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE device_presence');
    }
};
