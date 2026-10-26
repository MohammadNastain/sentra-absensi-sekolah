<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notifikasi dalam aplikasi (ADR 0010 §8): tugas baru terbit, tugas dinilai, tugas diminta revisi. Satu baris per
 * penerima. Isi sengaja hanya judul tugas dan nilai (tanpa komentar guru) agar tidak menyalin data pribadi siswa.
 * Terisolasi per sekolah oleh RLS; pembatasan per penerima dilakukan aplikasi (user_id = pengguna yang login).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE notifications (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                user_id uuid NOT NULL REFERENCES users (id),
                type text NOT NULL,
                title text NOT NULL,
                body text NOT NULL DEFAULT '',
                data jsonb NOT NULL DEFAULT '{}'::jsonb,
                read_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT notifications_type_check CHECK (type IN ('lms.assignment_published', 'lms.submission_graded', 'lms.submission_returned')),
                CONSTRAINT notifications_title_not_blank CHECK (btrim(title) <> '')
            );
            CREATE INDEX notifications_user_index ON notifications (school_id, user_id, created_at DESC);
            CREATE INDEX notifications_unread_index ON notifications (school_id, user_id) WHERE read_at IS NULL;

            ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;
            ALTER TABLE notifications FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON notifications
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE notifications');
    }
};
