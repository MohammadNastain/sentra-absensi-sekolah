<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Penutupan hari dan mekanisme alpa (ADR 0005 §5, D-33). Aturan alpa per sekolah (default mati), kolom verifikasi
 * pada proyeksi harian dengan CHECK yang menjamin `alpa` final selalu terverifikasi manusia, dan
 * `attendance_day_health` yang menahan aturan saat hari dinilai bermasalah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE schools ADD COLUMN absence_rule_enabled boolean NOT NULL DEFAULT false;
            ALTER TABLE schools ADD COLUMN absence_cutoff_time time NULL;
            ALTER TABLE schools ADD CONSTRAINT schools_absence_cutoff_check
                CHECK (NOT absence_rule_enabled OR absence_cutoff_time IS NOT NULL);

            ALTER TABLE daily_attendance ADD COLUMN absence_basis text NULL;
            ALTER TABLE daily_attendance ADD COLUMN verified_by_user_id uuid NULL REFERENCES users (id);
            ALTER TABLE daily_attendance ADD COLUMN verified_at timestamptz NULL;
            ALTER TABLE daily_attendance ADD CONSTRAINT daily_attendance_absence_basis_check
                CHECK (absence_basis IS NULL OR absence_basis IN ('rule', 'manual'));
            ALTER TABLE daily_attendance ADD CONSTRAINT daily_attendance_alpa_verified
                CHECK (attendance_status <> 'alpa' OR (verified_by_user_id IS NOT NULL AND verified_at IS NOT NULL));
            ALTER TABLE daily_attendance ADD CONSTRAINT daily_attendance_alpa_basis
                CHECK (attendance_status NOT IN ('alpa', 'alpa_belum_diverifikasi') OR absence_basis IS NOT NULL);

            CREATE TABLE attendance_day_health (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                attendance_date date NOT NULL,
                status text NOT NULL,
                reasons jsonb NOT NULL DEFAULT '[]'::jsonb,
                evaluated_at timestamptz NOT NULL DEFAULT now(),
                marked_by_user_id uuid NULL REFERENCES users (id),
                released_by_user_id uuid NULL REFERENCES users (id),
                release_reason text NULL,
                CONSTRAINT attendance_day_health_unique UNIQUE (school_id, attendance_date),
                CONSTRAINT attendance_day_health_status_check CHECK (status IN ('ok', 'perlu_pemeriksaan', 'dilepas')),
                CONSTRAINT attendance_day_health_release_check CHECK (
                    status <> 'dilepas' OR (released_by_user_id IS NOT NULL AND length(trim(release_reason)) >= 10)
                )
            );
            ALTER TABLE attendance_day_health ENABLE ROW LEVEL SECURITY;
            ALTER TABLE attendance_day_health FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON attendance_day_health
                USING (school_id = app_school_id()) WITH CHECK (school_id = app_school_id());
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE attendance_day_health;
            ALTER TABLE daily_attendance DROP CONSTRAINT daily_attendance_alpa_basis;
            ALTER TABLE daily_attendance DROP CONSTRAINT daily_attendance_alpa_verified;
            ALTER TABLE daily_attendance DROP CONSTRAINT daily_attendance_absence_basis_check;
            ALTER TABLE daily_attendance DROP COLUMN verified_at, DROP COLUMN verified_by_user_id, DROP COLUMN absence_basis;
            ALTER TABLE schools DROP CONSTRAINT schools_absence_cutoff_check;
            ALTER TABLE schools DROP COLUMN absence_cutoff_time, DROP COLUMN absence_rule_enabled;
            SQL);
    }
};
