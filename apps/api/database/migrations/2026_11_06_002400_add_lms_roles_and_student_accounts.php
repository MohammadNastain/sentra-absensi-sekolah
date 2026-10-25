<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0010 §1 — dasar akun LMS: peran `teacher` (guru mata pelajaran) dan `student`, serta tautan siswa ke akun.
 *
 * - `student` tidak boleh diundang lewat email (akun siswa dibuat dari NIS, L1b); hanya `teacher` ditambahkan ke CHECK undangan.
 * - students.membership_id mengikuti pola teachers.membership_id (D-07): login opsional, satu akun = satu siswa.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            INSERT INTO roles (code, name) VALUES
                ('teacher', 'Guru Mata Pelajaran'),
                ('student', 'Siswa');

            ALTER TABLE membership_invitations DROP CONSTRAINT membership_invitations_role_codes_check;
            ALTER TABLE membership_invitations ADD CONSTRAINT membership_invitations_role_codes_check CHECK (
                cardinality(role_codes) >= 1
                AND role_codes <@ ARRAY['school_admin', 'principal', 'homeroom_teacher', 'duty_officer', 'terminal_operator', 'teacher']::text[]
            );

            ALTER TABLE students ADD COLUMN membership_id uuid NULL;
            ALTER TABLE students ADD CONSTRAINT students_membership_id_unique UNIQUE (membership_id);
            ALTER TABLE students ADD CONSTRAINT students_membership_fk FOREIGN KEY (school_id, membership_id)
                REFERENCES school_memberships (school_id, id);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE students DROP CONSTRAINT students_membership_fk;
            ALTER TABLE students DROP CONSTRAINT students_membership_id_unique;
            ALTER TABLE students DROP COLUMN membership_id;

            ALTER TABLE membership_invitations DROP CONSTRAINT membership_invitations_role_codes_check;
            ALTER TABLE membership_invitations ADD CONSTRAINT membership_invitations_role_codes_check CHECK (
                cardinality(role_codes) >= 1
                AND role_codes <@ ARRAY['school_admin', 'principal', 'homeroom_teacher', 'duty_officer', 'terminal_operator']::text[]
            );

            DELETE FROM roles WHERE code IN ('teacher', 'student');
            SQL);
    }
};
