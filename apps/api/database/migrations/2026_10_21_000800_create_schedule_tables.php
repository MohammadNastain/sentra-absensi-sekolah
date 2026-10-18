<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hari libur dan jadwal berversi (DATABASE_DESIGN §3.3, Tahap 2D).
 *
 * Jadwal tidak diedit jamnya: perubahan dibuat sebagai versi baru dengan effective_from baru dan versi lama
 * ditutup (effective_until). Exclusion constraint menjamin untuk satu scope dan satu hari dalam seminggu
 * hanya ada satu jadwal aktif pada tanggal mana pun, sehingga resolusi jadwal selalu tunggal.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE holidays (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                name text NOT NULL,
                start_date date NOT NULL,
                end_date date NOT NULL,
                created_by_user_id uuid NULL REFERENCES users (id),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT holidays_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT holidays_name_not_blank CHECK (btrim(name) <> ''),
                CONSTRAINT holidays_dates_check CHECK (end_date >= start_date)
            );
            CREATE INDEX holidays_school_dates_index ON holidays (school_id, start_date, end_date);

            CREATE TABLE schedules (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                scope_type text NOT NULL,
                grade_level smallint NULL,
                class_id uuid NULL,
                -- Kunci scope tanpa NULL agar exclusion constraint berlaku untuk ketiga jenis scope.
                scope_key text GENERATED ALWAYS AS (
                    CASE scope_type
                        WHEN 'school' THEN 'school'
                        WHEN 'grade_level' THEN 'grade:' || grade_level::text
                        WHEN 'class' THEN 'class:' || class_id::text
                    END
                ) STORED,
                day_of_week smallint NOT NULL,
                check_in_start time NOT NULL,
                check_in_deadline time NOT NULL,
                late_grace_minutes smallint NOT NULL DEFAULT 0,
                check_out_start time NOT NULL,
                scheduled_check_out time NOT NULL,
                effective_from date NOT NULL,
                effective_until date NULL,
                is_active boolean NOT NULL DEFAULT true,
                created_by_user_id uuid NULL REFERENCES users (id),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT schedules_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT schedules_class_fk FOREIGN KEY (school_id, class_id)
                    REFERENCES classes (school_id, id),
                CONSTRAINT schedules_scope_type_check CHECK (scope_type IN ('school', 'grade_level', 'class')),
                CONSTRAINT schedules_scope_consistency CHECK (
                    (scope_type = 'school' AND grade_level IS NULL AND class_id IS NULL)
                    OR (scope_type = 'grade_level' AND grade_level IS NOT NULL AND grade_level BETWEEN 1 AND 13 AND class_id IS NULL)
                    OR (scope_type = 'class' AND class_id IS NOT NULL AND grade_level IS NULL)
                ),
                CONSTRAINT schedules_day_check CHECK (day_of_week BETWEEN 1 AND 7),
                CONSTRAINT schedules_grace_check CHECK (late_grace_minutes BETWEEN 0 AND 120),
                -- Jadwal yang melewati tengah malam tidak didukung di MVP.
                CONSTRAINT schedules_time_order CHECK (
                    check_in_start < check_in_deadline
                    AND check_in_deadline < check_out_start
                    AND check_out_start <= scheduled_check_out
                ),
                CONSTRAINT schedules_effective_check CHECK (effective_until IS NULL OR effective_until >= effective_from),
                CONSTRAINT schedules_no_overlap EXCLUDE USING gist (
                    school_id WITH =,
                    scope_key WITH =,
                    day_of_week WITH =,
                    daterange(effective_from, effective_until, '[]') WITH &&
                ) WHERE (is_active)
            );
            CREATE INDEX schedules_school_scope_index ON schedules (school_id, scope_key, day_of_week, effective_from);

            ALTER TABLE holidays ENABLE ROW LEVEL SECURITY;
            ALTER TABLE holidays FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON holidays
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());

            ALTER TABLE schedules ENABLE ROW LEVEL SECURITY;
            ALTER TABLE schedules FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON schedules
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE schedules;
            DROP TABLE holidays;
            SQL);
    }
};
