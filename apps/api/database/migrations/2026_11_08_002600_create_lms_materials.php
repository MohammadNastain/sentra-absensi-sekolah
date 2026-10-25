<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0010 §3 — materi LMS dan lampiran (L2). Tugas dan pengumpulan menyusul di L3; `lms_attachments` sudah siap
 * untuk tiga pemilik, tetapi CHECK-nya baru mengizinkan `material` sampai tabel pemilik lain ada.
 *
 * Semua tabel tenant: school_id + RLS + FK komposit. Isi materi teks polos (tanpa HTML) agar tidak ada XSS tersimpan.
 * Lampiran menyimpan hanya kunci penyimpanan acak; nama asli hanya metadata untuk header unduhan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE lms_materials (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                class_id uuid NOT NULL,
                subject_id uuid NOT NULL,
                author_user_id uuid NOT NULL REFERENCES users (id),
                title text NOT NULL,
                body text NOT NULL DEFAULT '',
                status text NOT NULL DEFAULT 'draft',
                published_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT lms_materials_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT lms_materials_class_fk FOREIGN KEY (school_id, class_id) REFERENCES classes (school_id, id),
                CONSTRAINT lms_materials_subject_fk FOREIGN KEY (school_id, subject_id) REFERENCES subjects (school_id, id),
                CONSTRAINT lms_materials_title_not_blank CHECK (btrim(title) <> ''),
                CONSTRAINT lms_materials_status_check CHECK (status IN ('draft', 'published')),
                CONSTRAINT lms_materials_published_at_check CHECK ((status = 'published') = (published_at IS NOT NULL))
            );
            CREATE INDEX lms_materials_pair_index ON lms_materials (school_id, class_id, subject_id, created_at DESC);

            CREATE TABLE lms_attachments (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                owner_type text NOT NULL,
                owner_id uuid NOT NULL,
                original_name text NOT NULL,
                storage_key text NOT NULL,
                mime text NOT NULL,
                size bigint NOT NULL,
                sha256 text NOT NULL,
                uploaded_by_user_id uuid NOT NULL REFERENCES users (id),
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT lms_attachments_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT lms_attachments_owner_type_check CHECK (owner_type IN ('material')),
                CONSTRAINT lms_attachments_size_check CHECK (size > 0 AND size <= 10485760),
                CONSTRAINT lms_attachments_storage_key_unique UNIQUE (storage_key)
            );
            CREATE INDEX lms_attachments_owner_index ON lms_attachments (school_id, owner_type, owner_id);

            -- Pemilik lampiran harus ada dan sesekolah. Satu fungsi memeriksa menurut owner_type; L3 menambah cabangnya.
            CREATE OR REPLACE FUNCTION lms_attachments_check_owner() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.owner_type = 'material' AND NOT EXISTS (
                    SELECT 1 FROM lms_materials WHERE school_id = NEW.school_id AND id = NEW.owner_id
                ) THEN
                    RAISE EXCEPTION 'Pemilik lampiran tidak ditemukan di sekolah ini' USING ERRCODE = '23503';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER lms_attachments_owner_check BEFORE INSERT OR UPDATE OF owner_type, owner_id, school_id
                ON lms_attachments FOR EACH ROW EXECUTE FUNCTION lms_attachments_check_owner();

            -- Menghapus materi menghapus baris lampirannya; berkas fisik dihapus aplikasi sebelum itu.
            CREATE OR REPLACE FUNCTION lms_materials_delete_attachments() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                DELETE FROM lms_attachments WHERE school_id = OLD.school_id AND owner_type = 'material' AND owner_id = OLD.id;
                RETURN OLD;
            END $$;
            CREATE TRIGGER lms_materials_cascade_attachments BEFORE DELETE ON lms_materials
                FOR EACH ROW EXECUTE FUNCTION lms_materials_delete_attachments();

            ALTER TABLE lms_materials ENABLE ROW LEVEL SECURITY;
            ALTER TABLE lms_materials FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON lms_materials
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());

            ALTER TABLE lms_attachments ENABLE ROW LEVEL SECURITY;
            ALTER TABLE lms_attachments FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON lms_attachments
                USING (school_id = app_school_id())
                WITH CHECK (school_id = app_school_id());
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE lms_attachments;
            DROP TABLE lms_materials;
            DROP FUNCTION lms_attachments_check_owner();
            DROP FUNCTION lms_materials_delete_attachments();
            SQL);
    }
};
