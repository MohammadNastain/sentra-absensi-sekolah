<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mata pelajaran dan jadwal mengajar (fitur tambahan setelah Tahap 6): siapa mengajar apa, di kelas mana, hari dan jam berapa.
 *
 * Jadwal mengajar adalah data rujukan (belum dipakai untuk absensi per jam). Dua exclusion constraint
 * menjamin satu guru tidak mengajar di dua tempat pada jam yang beririsan, dan satu kelas tidak punya
 * dua pelajaran pada jam yang beririsan; slot yang berurutan (selesai 08.00, mulai 08.00) tidak bentrok.
 * Impor guru dan jadwal memakai tabel staging yang sama dengan impor siswa (tipe `teachers`, `teaching_schedule`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE teachers ADD COLUMN email text NULL;
            ALTER TABLE teachers ADD CONSTRAINT teachers_email_not_blank CHECK (email IS NULL OR btrim(email) <> '');

            CREATE TABLE subjects (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                name text NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT subjects_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT subjects_name_not_blank CHECK (btrim(name) <> '')
            );
            CREATE UNIQUE INDEX subjects_school_name_unique ON subjects (school_id, lower(name));

            CREATE TABLE teacher_subjects (
                school_id uuid NOT NULL REFERENCES schools (id),
                teacher_id uuid NOT NULL,
                subject_id uuid NOT NULL,
                PRIMARY KEY (teacher_id, subject_id),
                CONSTRAINT teacher_subjects_teacher_fk FOREIGN KEY (school_id, teacher_id)
                    REFERENCES teachers (school_id, id) ON DELETE CASCADE,
                CONSTRAINT teacher_subjects_subject_fk FOREIGN KEY (school_id, subject_id)
                    REFERENCES subjects (school_id, id) ON DELETE CASCADE
            );
            CREATE INDEX teacher_subjects_subject_index ON teacher_subjects (school_id, subject_id);

            CREATE TABLE teaching_slots (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                class_id uuid NOT NULL,
                subject_id uuid NOT NULL,
                teacher_id uuid NOT NULL,
                day_of_week smallint NOT NULL,
                start_time time NOT NULL,
                end_time time NOT NULL,
                created_by_user_id uuid NULL REFERENCES users (id),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT teaching_slots_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT teaching_slots_class_fk FOREIGN KEY (school_id, class_id) REFERENCES classes (school_id, id),
                CONSTRAINT teaching_slots_subject_fk FOREIGN KEY (school_id, subject_id) REFERENCES subjects (school_id, id),
                CONSTRAINT teaching_slots_teacher_fk FOREIGN KEY (school_id, teacher_id) REFERENCES teachers (school_id, id),
                CONSTRAINT teaching_slots_day_check CHECK (day_of_week BETWEEN 1 AND 7),
                CONSTRAINT teaching_slots_time_check CHECK (end_time > start_time),
                CONSTRAINT teaching_slots_teacher_no_overlap EXCLUDE USING gist (
                    school_id WITH =, teacher_id WITH =, day_of_week WITH =,
                    tsrange(DATE '2000-01-01' + start_time, DATE '2000-01-01' + end_time) WITH &&
                ),
                CONSTRAINT teaching_slots_class_no_overlap EXCLUDE USING gist (
                    school_id WITH =, class_id WITH =, day_of_week WITH =,
                    tsrange(DATE '2000-01-01' + start_time, DATE '2000-01-01' + end_time) WITH &&
                )
            );
            CREATE INDEX teaching_slots_class_index ON teaching_slots (school_id, class_id, day_of_week, start_time);
            CREATE INDEX teaching_slots_teacher_index ON teaching_slots (school_id, teacher_id, day_of_week, start_time);
            CREATE INDEX teaching_slots_subject_index ON teaching_slots (school_id, subject_id);

            ALTER TABLE subjects ENABLE ROW LEVEL SECURITY;
            ALTER TABLE subjects FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON subjects
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());

            ALTER TABLE teacher_subjects ENABLE ROW LEVEL SECURITY;
            ALTER TABLE teacher_subjects FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON teacher_subjects
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());

            ALTER TABLE teaching_slots ENABLE ROW LEVEL SECURITY;
            ALTER TABLE teaching_slots FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON teaching_slots
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());

            ALTER TABLE import_batches DROP CONSTRAINT import_batches_type_check;
            ALTER TABLE import_batches ADD CONSTRAINT import_batches_type_check
                CHECK (type IN ('students', 'teachers', 'teaching_schedule'));
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM import_batches WHERE type <> 'students';
            ALTER TABLE import_batches DROP CONSTRAINT import_batches_type_check;
            ALTER TABLE import_batches ADD CONSTRAINT import_batches_type_check CHECK (type IN ('students'));
            DROP TABLE teaching_slots;
            DROP TABLE teacher_subjects;
            DROP TABLE subjects;
            ALTER TABLE teachers DROP CONSTRAINT teachers_email_not_blank;
            ALTER TABLE teachers DROP COLUMN email;
            SQL);
    }
};
