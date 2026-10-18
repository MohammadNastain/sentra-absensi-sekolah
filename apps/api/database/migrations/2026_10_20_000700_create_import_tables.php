<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Staging impor siswa (DATABASE_DESIGN §3.6, Tahap 2C): file diunggah → divalidasi async → admin meninjau
 * preview → commit baris valid saja (D-15). Baris disimpan apa adanya (`payload`) beserta hasil normalisasi
 * dan daftar kesalahan, sehingga baris gagal bisa diunduh sebagai file perbaikan. Data staging dihapus
 * terjadwal (D-26).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE import_batches (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                type text NOT NULL DEFAULT 'students',
                status text NOT NULL DEFAULT 'uploaded',
                original_filename text NOT NULL,
                storage_path text NOT NULL,
                file_sha256 text NOT NULL,
                total_rows integer NOT NULL DEFAULT 0,
                valid_rows integer NOT NULL DEFAULT 0,
                invalid_rows integer NOT NULL DEFAULT 0,
                committed_rows integer NOT NULL DEFAULT 0,
                error_message text NULL,
                uploaded_by_user_id uuid NOT NULL REFERENCES users (id),
                committed_by_user_id uuid NULL REFERENCES users (id),
                committed_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT import_batches_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT import_batches_type_check CHECK (type IN ('students')),
                CONSTRAINT import_batches_status_check CHECK (status IN
                    ('uploaded', 'validating', 'validated', 'committing', 'committed', 'failed', 'cancelled')),
                CONSTRAINT import_batches_counts_check CHECK (
                    total_rows >= 0 AND valid_rows >= 0 AND invalid_rows >= 0 AND committed_rows >= 0
                )
            );
            CREATE INDEX import_batches_school_created_index ON import_batches (school_id, created_at DESC);

            CREATE TABLE import_rows (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                batch_id uuid NOT NULL,
                row_number integer NOT NULL,
                payload jsonb NOT NULL,
                normalized jsonb NULL,
                status text NOT NULL,
                errors jsonb NOT NULL DEFAULT '[]'::jsonb,
                CONSTRAINT import_rows_batch_fk FOREIGN KEY (school_id, batch_id)
                    REFERENCES import_batches (school_id, id) ON DELETE CASCADE,
                CONSTRAINT import_rows_batch_row_unique UNIQUE (batch_id, row_number),
                CONSTRAINT import_rows_status_check CHECK (status IN ('valid', 'invalid', 'committed', 'skipped'))
            );
            CREATE INDEX import_rows_batch_status_index ON import_rows (school_id, batch_id, status, row_number);

            ALTER TABLE import_batches ENABLE ROW LEVEL SECURITY;
            ALTER TABLE import_batches FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON import_batches
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());

            ALTER TABLE import_rows ENABLE ROW LEVEL SECURITY;
            ALTER TABLE import_rows FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON import_rows
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE import_rows;
            DROP TABLE import_batches;
            SQL);
    }
};
