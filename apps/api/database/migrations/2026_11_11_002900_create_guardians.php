<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0011 §1–§2, §7 — dasar portal orang tua (G1): peran `guardian`, tabel wali dan tautan wali-anak, dan fitur `parent_portal.*`.
 *
 * - `guardians.membership_id` mengikuti pola teachers/students (unik; terisi setelah undangan diterima). Surel tidak disimpan di sini.
 * - `student_guardians`: satu baris per pasangan; satu wali utama per siswa. Batas 4 wali per siswa ditegakkan aplikasi (G2).
 * - `guardian` boleh diundang (CHECK undangan), tetapi hanya lewat alur wali yang menautkannya ke anak, bukan undangan umum.
 * - Fitur anak `parent_portal.*` masuk paket Standard dan Premium (lms hanya Premium); belum dirilis (bendera config).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            INSERT INTO roles (code, name) VALUES ('guardian', 'Orang Tua/Wali');

            ALTER TABLE membership_invitations DROP CONSTRAINT membership_invitations_role_codes_check;
            ALTER TABLE membership_invitations ADD CONSTRAINT membership_invitations_role_codes_check CHECK (
                cardinality(role_codes) >= 1
                AND role_codes <@ ARRAY['school_admin', 'principal', 'homeroom_teacher', 'duty_officer', 'terminal_operator', 'teacher', 'guardian']::text[]
            );

            CREATE TABLE guardians (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                full_name text NOT NULL,
                phone text NULL,
                membership_id uuid NULL,
                status text NOT NULL DEFAULT 'active',
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT guardians_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT guardians_membership_id_unique UNIQUE (membership_id),
                CONSTRAINT guardians_membership_fk FOREIGN KEY (school_id, membership_id) REFERENCES school_memberships (school_id, id),
                CONSTRAINT guardians_full_name_not_blank CHECK (btrim(full_name) <> ''),
                CONSTRAINT guardians_status_check CHECK (status IN ('active', 'inactive'))
            );

            CREATE TABLE student_guardians (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                student_id uuid NOT NULL,
                guardian_id uuid NOT NULL,
                relationship text NOT NULL,
                is_primary boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT student_guardians_pair_unique UNIQUE (student_id, guardian_id),
                CONSTRAINT student_guardians_student_fk FOREIGN KEY (school_id, student_id) REFERENCES students (school_id, id),
                CONSTRAINT student_guardians_guardian_fk FOREIGN KEY (school_id, guardian_id) REFERENCES guardians (school_id, id),
                CONSTRAINT student_guardians_relationship_check CHECK (relationship IN ('ayah', 'ibu', 'wali'))
            );
            CREATE UNIQUE INDEX student_guardians_one_primary ON student_guardians (student_id) WHERE is_primary;
            CREATE INDEX student_guardians_guardian_index ON student_guardians (school_id, guardian_id);

            ALTER TABLE guardians ENABLE ROW LEVEL SECURITY;
            ALTER TABLE guardians FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON guardians
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());

            ALTER TABLE student_guardians ENABLE ROW LEVEL SECURITY;
            ALTER TABLE student_guardians FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON student_guardians
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());

            INSERT INTO features (code, parent_code) VALUES
                ('parent_portal.attendance', 'parent_portal'),
                ('parent_portal.leave', 'parent_portal'),
                ('parent_portal.lms', 'parent_portal');

            INSERT INTO plan_features (plan_code, feature_code) VALUES
                ('standard', 'parent_portal'), ('standard', 'parent_portal.attendance'), ('standard', 'parent_portal.leave'),
                ('premium', 'parent_portal'), ('premium', 'parent_portal.attendance'), ('premium', 'parent_portal.leave'),
                ('premium', 'parent_portal.lms');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM plan_features WHERE feature_code LIKE 'parent_portal%';
            DELETE FROM school_feature_overrides WHERE feature_code LIKE 'parent_portal.%';
            DELETE FROM features WHERE code LIKE 'parent_portal.%';

            DROP TABLE student_guardians;
            DROP TABLE guardians;

            ALTER TABLE membership_invitations DROP CONSTRAINT membership_invitations_role_codes_check;
            ALTER TABLE membership_invitations ADD CONSTRAINT membership_invitations_role_codes_check CHECK (
                cardinality(role_codes) >= 1
                AND role_codes <@ ARRAY['school_admin', 'principal', 'homeroom_teacher', 'duty_officer', 'terminal_operator', 'teacher']::text[]
            );

            DELETE FROM roles WHERE code = 'guardian';
            SQL);
    }
};
