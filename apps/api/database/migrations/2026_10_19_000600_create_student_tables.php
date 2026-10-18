<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Siswa dan penempatan kelas (DATABASE_DESIGN §3.2, Tahap 2B).
 *
 * Pindah kelas = menutup penempatan lama dan membuka yang baru dalam satu transaksi; exclusion constraint
 * menjamin seorang siswa tidak pernah berada di dua kelas pada tanggal yang sama. Siswa tidak dihapus:
 * status inactive/graduated/transferred menjaga riwayat absensi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE students (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                student_number text NOT NULL,
                nisn text NULL,
                full_name text NOT NULL,
                date_of_birth date NULL,
                status text NOT NULL DEFAULT 'active',
                status_changed_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT students_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT students_school_number_unique UNIQUE (school_id, student_number),
                CONSTRAINT students_number_not_blank CHECK (btrim(student_number) <> ''),
                CONSTRAINT students_full_name_not_blank CHECK (btrim(full_name) <> ''),
                CONSTRAINT students_nisn_format CHECK (nisn IS NULL OR nisn ~ '^[0-9]{10}$'),
                CONSTRAINT students_status_check CHECK (status IN ('active', 'inactive', 'graduated', 'transferred'))
            );
            CREATE UNIQUE INDEX students_nisn_unique ON students (school_id, nisn) WHERE nisn IS NOT NULL;
            CREATE INDEX students_full_name_trgm ON students USING gin (full_name gin_trgm_ops);
            CREATE INDEX students_school_name_index ON students (school_id, full_name);

            CREATE TABLE class_enrollments (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                student_id uuid NOT NULL,
                class_id uuid NOT NULL,
                start_date date NOT NULL,
                end_date date NULL,
                created_by_user_id uuid NULL REFERENCES users (id),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT class_enrollments_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT class_enrollments_student_fk FOREIGN KEY (school_id, student_id)
                    REFERENCES students (school_id, id),
                CONSTRAINT class_enrollments_class_fk FOREIGN KEY (school_id, class_id)
                    REFERENCES classes (school_id, id),
                CONSTRAINT class_enrollments_dates_check CHECK (end_date IS NULL OR end_date >= start_date),
                -- Satu siswa tidak bisa berada di dua kelas pada tanggal yang sama (end_date NULL = tanpa batas akhir).
                CONSTRAINT class_enrollments_no_overlap EXCLUDE USING gist (
                    school_id WITH =,
                    student_id WITH =,
                    daterange(start_date, end_date, '[]') WITH &&
                )
            );
            CREATE INDEX class_enrollments_class_index ON class_enrollments (school_id, class_id);
            CREATE INDEX class_enrollments_student_index ON class_enrollments (school_id, student_id, start_date);

            ALTER TABLE students ENABLE ROW LEVEL SECURITY;
            ALTER TABLE students FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON students
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());

            ALTER TABLE class_enrollments ENABLE ROW LEVEL SECURITY;
            ALTER TABLE class_enrollments FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON class_enrollments
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE class_enrollments;
            DROP TABLE students;
            SQL);
    }
};
