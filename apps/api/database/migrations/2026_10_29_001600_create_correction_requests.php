<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pengajuan koreksi absensi (Tahap 5C). `attendance_adjustments` append-only, jadi pengajuan yang menunggu keputusan
 * hidup di tabel yang dapat berubah ini; keputusan "setuju" menulis baris adjustment dan menerapkannya ke proyeksi harian.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE correction_requests (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                daily_attendance_id uuid NOT NULL,
                student_id uuid NOT NULL,
                attendance_date date NOT NULL,
                adjustment_type text NOT NULL,
                to_value jsonb NOT NULL,
                reason text NOT NULL,
                status text NOT NULL DEFAULT 'pending',
                requested_by_user_id uuid NOT NULL REFERENCES users (id),
                decided_by_user_id uuid NULL REFERENCES users (id),
                decided_at timestamptz NULL,
                decision_note text NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT correction_requests_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT correction_requests_daily_fk FOREIGN KEY (school_id, daily_attendance_id) REFERENCES daily_attendance (school_id, id),
                CONSTRAINT correction_requests_student_fk FOREIGN KEY (school_id, student_id) REFERENCES students (school_id, id),
                CONSTRAINT correction_requests_type_check CHECK (adjustment_type IN ('status_change', 'early_leave_decision')),
                CONSTRAINT correction_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled')),
                CONSTRAINT correction_requests_reason_check CHECK (length(trim(reason)) >= 10),
                CONSTRAINT correction_requests_decision_check CHECK (
                    status = 'pending' OR (decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL)
                )
            );
            CREATE UNIQUE INDEX correction_requests_one_pending
                ON correction_requests (school_id, daily_attendance_id, adjustment_type) WHERE status = 'pending';
            CREATE INDEX correction_requests_status_index ON correction_requests (school_id, status, created_at DESC);

            ALTER TABLE correction_requests ENABLE ROW LEVEL SECURITY;
            ALTER TABLE correction_requests FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON correction_requests
                USING (school_id = app_school_id()) WITH CHECK (school_id = app_school_id());
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE correction_requests;');
    }
};
