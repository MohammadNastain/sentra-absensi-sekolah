<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Histori koreksi terstruktur (DATABASE_DESIGN §3.5), append-only. Tahap 4 memakainya untuk resolusi antrean
 * `needs_review`; satu event hanya dapat diselesaikan sekali. Event tidak diubah: koreksi hidup di tabel ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE attendance_adjustments (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                daily_attendance_id uuid NOT NULL,
                student_id uuid NOT NULL,
                attendance_date date NOT NULL,
                source_event_id uuid NULL,
                adjustment_type text NOT NULL,
                from_value jsonb NULL,
                to_value jsonb NULL,
                reason text NOT NULL,
                status text NOT NULL,
                requested_by_user_id uuid NOT NULL REFERENCES users (id),
                decided_by_user_id uuid NULL REFERENCES users (id),
                decided_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT attendance_adjustments_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT attendance_adjustments_daily_fk FOREIGN KEY (school_id, daily_attendance_id) REFERENCES daily_attendance (school_id, id),
                CONSTRAINT attendance_adjustments_student_fk FOREIGN KEY (school_id, student_id) REFERENCES students (school_id, id),
                CONSTRAINT attendance_adjustments_event_fk FOREIGN KEY (school_id, source_event_id) REFERENCES attendance_events (school_id, id),
                CONSTRAINT attendance_adjustments_type_check CHECK (adjustment_type IN ('status_change', 'check_in_time', 'check_out_time', 'early_leave_decision', 'review_resolution')),
                CONSTRAINT attendance_adjustments_status_check CHECK (status IN ('pending', 'approved', 'rejected')),
                CONSTRAINT attendance_adjustments_reason_check CHECK (length(trim(reason)) >= 10)
            );
            CREATE UNIQUE INDEX attendance_adjustments_one_resolution_per_event
                ON attendance_adjustments (school_id, source_event_id) WHERE adjustment_type = 'review_resolution';
            CREATE INDEX attendance_adjustments_date_index ON attendance_adjustments (school_id, attendance_date);

            ALTER TABLE attendance_adjustments ENABLE ROW LEVEL SECURITY;
            ALTER TABLE attendance_adjustments FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON attendance_adjustments
                USING (school_id = app_school_id()) WITH CHECK (school_id = app_school_id());

            CREATE TRIGGER attendance_adjustments_append_only
                BEFORE UPDATE OR DELETE ON attendance_adjustments
                FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation();
            CREATE TRIGGER attendance_adjustments_no_truncate
                BEFORE TRUNCATE ON attendance_adjustments
                FOR EACH STATEMENT EXECUTE FUNCTION reject_append_only_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE attendance_adjustments;');
    }
};
