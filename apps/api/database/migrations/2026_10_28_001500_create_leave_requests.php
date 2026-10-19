<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Izin/sakit/dispensasi (DATABASE_DESIGN §3.5, Tahap 5B). Diajukan staf untuk siswa, disetujui admin/petugas piket,
 * lalu diproyeksikan ke `daily_attendance` (kolom `leave_request_id`). Lampiran disimpan di disk privat; hanya
 * path dan metadata yang ada di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE leave_requests (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                student_id uuid NOT NULL,
                leave_type text NOT NULL,
                start_date date NOT NULL,
                end_date date NOT NULL,
                reason text NOT NULL,
                status text NOT NULL DEFAULT 'pending',
                attachment_path text NULL,
                attachment_name text NULL,
                attachment_mime text NULL,
                requested_by_user_id uuid NOT NULL REFERENCES users (id),
                decided_by_user_id uuid NULL REFERENCES users (id),
                decided_at timestamptz NULL,
                decision_note text NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT leave_requests_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT leave_requests_student_fk FOREIGN KEY (school_id, student_id) REFERENCES students (school_id, id),
                CONSTRAINT leave_requests_type_check CHECK (leave_type IN ('izin', 'sakit', 'dispensasi')),
                CONSTRAINT leave_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled')),
                CONSTRAINT leave_requests_range_check CHECK (end_date >= start_date AND end_date - start_date <= 30),
                CONSTRAINT leave_requests_reason_check CHECK (length(trim(reason)) >= 5),
                CONSTRAINT leave_requests_decision_check CHECK (
                    status = 'pending' OR (decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL)
                )
            );
            CREATE INDEX leave_requests_student_index ON leave_requests (school_id, student_id, start_date, end_date);
            CREATE INDEX leave_requests_status_index ON leave_requests (school_id, status, created_at DESC);
            -- Izin aktif (menunggu/disetujui) milik satu siswa tidak boleh bertumpang tindih.
            ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_no_overlap
                EXCLUDE USING gist (school_id WITH =, student_id WITH =, daterange(start_date, end_date, '[]') WITH &&)
                WHERE (status IN ('pending', 'approved'));

            ALTER TABLE leave_requests ENABLE ROW LEVEL SECURITY;
            ALTER TABLE leave_requests FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON leave_requests
                USING (school_id = app_school_id()) WITH CHECK (school_id = app_school_id());

            ALTER TABLE daily_attendance ADD COLUMN leave_request_id uuid NULL;
            ALTER TABLE daily_attendance ADD CONSTRAINT daily_attendance_leave_fk
                FOREIGN KEY (school_id, leave_request_id) REFERENCES leave_requests (school_id, id);
            CREATE INDEX daily_attendance_leave_index ON daily_attendance (school_id, leave_request_id) WHERE leave_request_id IS NOT NULL;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE daily_attendance DROP COLUMN leave_request_id;
            DROP TABLE leave_requests;
            SQL);
    }
};
