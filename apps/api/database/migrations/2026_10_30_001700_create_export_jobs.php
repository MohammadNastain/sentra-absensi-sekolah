<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pekerjaan ekspor laporan (Tahap 5D). Satu baris per permintaan; file hasil disimpan privat dan dihapus setelah
 * `expires_at`. Batas kelas wali kelas dibekukan pada `class_scope` saat diminta, sehingga job tidak bergantung pada sesi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE export_jobs (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                requested_by_user_id uuid NOT NULL REFERENCES users (id),
                report text NOT NULL,
                format text NOT NULL,
                filters jsonb NOT NULL DEFAULT '{}'::jsonb,
                class_scope jsonb NULL,
                status text NOT NULL DEFAULT 'queued',
                file_path text NULL,
                file_size bigint NULL,
                row_count integer NULL,
                error_message text NULL,
                expires_at timestamptz NOT NULL,
                finished_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT export_jobs_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT export_jobs_report_check CHECK (report IN ('summary', 'students', 'lateness', 'daily', 'monthly', 'events')),
                CONSTRAINT export_jobs_format_check CHECK (format IN ('csv', 'xlsx')),
                CONSTRAINT export_jobs_status_check CHECK (status IN ('queued', 'running', 'done', 'failed')),
                CONSTRAINT export_jobs_done_check CHECK (status NOT IN ('done', 'failed') OR finished_at IS NOT NULL)
            );
            CREATE INDEX export_jobs_requester_index ON export_jobs (school_id, requested_by_user_id, created_at DESC);
            CREATE INDEX export_jobs_expiry_index ON export_jobs (expires_at) WHERE file_path IS NOT NULL;

            ALTER TABLE export_jobs ENABLE ROW LEVEL SECURITY;
            ALTER TABLE export_jobs FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON export_jobs
                USING (school_id = app_school_id()) WITH CHECK (school_id = app_school_id());
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE export_jobs;');
    }
};
