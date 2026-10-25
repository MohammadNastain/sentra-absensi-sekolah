<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0010 §3 — tugas, pengumpulan, dan penilaian (L3); lampiran diperluas ke pemilik `assignment` dan `submission`.
 *
 * - Satu pengumpulan per siswa per tugas (UNIK). Nilai 0..max_score dijaga CHECK sederhana (nilai ≥ 0) dan
 *   aplikasi (terhadap max_score tugas); status `graded` wajib bernilai, `returned_for_revision` wajib berkomentar.
 * - Tugas yang sudah punya pengumpulan tidak dapat dihapus (FK tanpa cascade); tutup tugasnya.
 * - Status keterlambatan bukan status melainkan atribut `is_late` (terlambat dan dinilai bisa bersamaan).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE lms_assignments (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                class_id uuid NOT NULL,
                subject_id uuid NOT NULL,
                author_user_id uuid NOT NULL REFERENCES users (id),
                title text NOT NULL,
                instructions text NOT NULL DEFAULT '',
                due_at timestamptz NULL,
                max_score numeric(7, 2) NOT NULL DEFAULT 100,
                accept_late boolean NOT NULL DEFAULT false,
                status text NOT NULL DEFAULT 'draft',
                published_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT lms_assignments_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT lms_assignments_class_fk FOREIGN KEY (school_id, class_id) REFERENCES classes (school_id, id),
                CONSTRAINT lms_assignments_subject_fk FOREIGN KEY (school_id, subject_id) REFERENCES subjects (school_id, id),
                CONSTRAINT lms_assignments_title_not_blank CHECK (btrim(title) <> ''),
                CONSTRAINT lms_assignments_status_check CHECK (status IN ('draft', 'published', 'closed')),
                CONSTRAINT lms_assignments_max_score_check CHECK (max_score > 0 AND max_score <= 10000),
                CONSTRAINT lms_assignments_published_at_check CHECK ((status = 'draft') = (published_at IS NULL))
            );
            CREATE INDEX lms_assignments_pair_index ON lms_assignments (school_id, class_id, subject_id, created_at DESC);

            CREATE TABLE lms_submissions (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                assignment_id uuid NOT NULL,
                student_id uuid NOT NULL,
                body text NOT NULL DEFAULT '',
                status text NOT NULL DEFAULT 'submitted',
                submitted_at timestamptz NOT NULL,
                is_late boolean NOT NULL DEFAULT false,
                score numeric(7, 2) NULL,
                feedback text NULL,
                graded_by_user_id uuid NULL REFERENCES users (id),
                graded_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT lms_submissions_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT lms_submissions_one_per_student UNIQUE (assignment_id, student_id),
                CONSTRAINT lms_submissions_assignment_fk FOREIGN KEY (school_id, assignment_id) REFERENCES lms_assignments (school_id, id),
                CONSTRAINT lms_submissions_student_fk FOREIGN KEY (school_id, student_id) REFERENCES students (school_id, id),
                CONSTRAINT lms_submissions_status_check CHECK (status IN ('submitted', 'returned_for_revision', 'graded')),
                CONSTRAINT lms_submissions_score_check CHECK (score IS NULL OR score >= 0),
                CONSTRAINT lms_submissions_graded_has_score CHECK (status <> 'graded' OR (score IS NOT NULL AND graded_by_user_id IS NOT NULL AND graded_at IS NOT NULL)),
                CONSTRAINT lms_submissions_returned_has_feedback CHECK (status <> 'returned_for_revision' OR (feedback IS NOT NULL AND btrim(feedback) <> ''))
            );
            CREATE INDEX lms_submissions_student_index ON lms_submissions (school_id, student_id);

            -- Lampiran: tiga pemilik. Pemeriksaan pemilik dan hapus berjenjang diperluas.
            ALTER TABLE lms_attachments DROP CONSTRAINT lms_attachments_owner_type_check;
            ALTER TABLE lms_attachments ADD CONSTRAINT lms_attachments_owner_type_check CHECK (owner_type IN ('material', 'assignment', 'submission'));

            CREATE OR REPLACE FUNCTION lms_attachments_check_owner() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE found boolean := false;
            BEGIN
                IF NEW.owner_type = 'material' THEN
                    SELECT EXISTS (SELECT 1 FROM lms_materials WHERE school_id = NEW.school_id AND id = NEW.owner_id) INTO found;
                ELSIF NEW.owner_type = 'assignment' THEN
                    SELECT EXISTS (SELECT 1 FROM lms_assignments WHERE school_id = NEW.school_id AND id = NEW.owner_id) INTO found;
                ELSIF NEW.owner_type = 'submission' THEN
                    SELECT EXISTS (SELECT 1 FROM lms_submissions WHERE school_id = NEW.school_id AND id = NEW.owner_id) INTO found;
                END IF;
                IF NOT found THEN
                    RAISE EXCEPTION 'Pemilik lampiran tidak ditemukan di sekolah ini' USING ERRCODE = '23503';
                END IF;
                RETURN NEW;
            END $$;

            CREATE OR REPLACE FUNCTION lms_assignments_delete_attachments() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                DELETE FROM lms_attachments WHERE school_id = OLD.school_id AND owner_type = 'assignment' AND owner_id = OLD.id;
                RETURN OLD;
            END $$;
            CREATE TRIGGER lms_assignments_cascade_attachments BEFORE DELETE ON lms_assignments
                FOR EACH ROW EXECUTE FUNCTION lms_assignments_delete_attachments();

            ALTER TABLE lms_assignments ENABLE ROW LEVEL SECURITY;
            ALTER TABLE lms_assignments FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON lms_assignments
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());

            ALTER TABLE lms_submissions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE lms_submissions FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON lms_submissions
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM lms_attachments WHERE owner_type <> 'material';
            ALTER TABLE lms_attachments DROP CONSTRAINT lms_attachments_owner_type_check;
            ALTER TABLE lms_attachments ADD CONSTRAINT lms_attachments_owner_type_check CHECK (owner_type IN ('material'));

            CREATE OR REPLACE FUNCTION lms_attachments_check_owner() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.owner_type = 'material' AND NOT EXISTS (
                    SELECT 1 FROM lms_materials WHERE school_id = NEW.school_id AND id = NEW.owner_id
                ) THEN
                    RAISE EXCEPTION 'Pemilik lampiran tidak ditemukan di sekolah ini' USING ERRCODE = '23503';
                END IF;
                RETURN NEW;
            END $$;

            DROP TABLE lms_submissions;
            DROP TABLE lms_assignments;
            DROP FUNCTION lms_assignments_delete_attachments();
            SQL);
    }
};
