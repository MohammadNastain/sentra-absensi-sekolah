<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data akademik sekolah: guru, tahun ajaran, kelas (DATABASE_DESIGN §3.2, Tahap 2).
 *
 * Semua tabel milik sekolah: FK lintas entitas bersifat komposit (school_id, x_id) sehingga mustahil
 * menautkan data dua sekolah, dan RLS dipaksa dengan policy tenant_isolation. Data inti tidak di-hard-delete:
 * guru memakai status, kelas diarsipkan, tahun ajaran dinonaktifkan lewat aktivasi tahun lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE teachers (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                -- Login opsional (D-07): guru tanpa akun tetap bisa menjadi wali kelas di data.
                membership_id uuid NULL,
                full_name text NOT NULL,
                employee_number text NULL,
                status text NOT NULL DEFAULT 'active',
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT teachers_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT teachers_membership_id_unique UNIQUE (membership_id),
                CONSTRAINT teachers_membership_fk FOREIGN KEY (school_id, membership_id)
                    REFERENCES school_memberships (school_id, id),
                CONSTRAINT teachers_full_name_not_blank CHECK (btrim(full_name) <> ''),
                CONSTRAINT teachers_employee_number_not_blank CHECK (employee_number IS NULL OR btrim(employee_number) <> ''),
                CONSTRAINT teachers_status_check CHECK (status IN ('active', 'inactive'))
            );
            CREATE UNIQUE INDEX teachers_employee_number_unique
                ON teachers (school_id, employee_number) WHERE employee_number IS NOT NULL;
            CREATE INDEX teachers_school_name_index ON teachers (school_id, full_name);

            CREATE TABLE academic_years (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                name text NOT NULL,
                start_date date NOT NULL,
                end_date date NOT NULL,
                is_active boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT academic_years_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT academic_years_school_name_unique UNIQUE (school_id, name),
                CONSTRAINT academic_years_name_not_blank CHECK (btrim(name) <> ''),
                CONSTRAINT academic_years_dates_check CHECK (end_date > start_date),
                -- Tahun ajaran satu sekolah tidak boleh tumpang tindih.
                CONSTRAINT academic_years_no_overlap EXCLUDE USING gist (
                    school_id WITH =,
                    daterange(start_date, end_date, '[]') WITH &&
                )
            );
            -- Paling banyak satu tahun ajaran aktif per sekolah.
            CREATE UNIQUE INDEX academic_years_one_active ON academic_years (school_id) WHERE is_active;

            CREATE TABLE classes (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                academic_year_id uuid NOT NULL,
                name text NOT NULL,
                grade_level smallint NOT NULL,
                homeroom_teacher_id uuid NULL,
                status text NOT NULL DEFAULT 'active',
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT classes_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT classes_year_name_unique UNIQUE (school_id, academic_year_id, name),
                CONSTRAINT classes_academic_year_fk FOREIGN KEY (school_id, academic_year_id)
                    REFERENCES academic_years (school_id, id),
                CONSTRAINT classes_homeroom_teacher_fk FOREIGN KEY (school_id, homeroom_teacher_id)
                    REFERENCES teachers (school_id, id),
                CONSTRAINT classes_name_not_blank CHECK (btrim(name) <> ''),
                CONSTRAINT classes_grade_level_check CHECK (grade_level BETWEEN 1 AND 13),
                CONSTRAINT classes_status_check CHECK (status IN ('active', 'archived'))
            );
            CREATE INDEX classes_homeroom_teacher_index ON classes (school_id, homeroom_teacher_id);

            -- RLS: gagal tertutup bila konteks sekolah belum diset.
            ALTER TABLE teachers ENABLE ROW LEVEL SECURITY;
            ALTER TABLE teachers FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON teachers
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());

            ALTER TABLE academic_years ENABLE ROW LEVEL SECURITY;
            ALTER TABLE academic_years FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON academic_years
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());

            ALTER TABLE classes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE classes FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON classes
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE classes;
            DROP TABLE academic_years;
            DROP TABLE teachers;
            SQL);
    }
};
