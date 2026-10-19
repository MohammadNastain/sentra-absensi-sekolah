<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retensi alamat IP audit (D-26, 90 hari). `audit_logs` tetap append-only: satu-satunya perubahan yang diizinkan
 * adalah mengosongkan `ip_address` (kolom lain harus identik), dan role aplikasi hanya diberi UPDATE pada kolom itu.
 * DELETE dan TRUNCATE tetap ditolak.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_allow_ip_scrub() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    IF OLD.ip_address IS NOT NULL
                       AND NEW.ip_address IS NULL
                       AND (to_jsonb(NEW) - 'ip_address') = (to_jsonb(OLD) - 'ip_address') THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'Tabel % bersifat append-only: hanya pengosongan ip_address yang diizinkan', TG_TABLE_NAME
                        USING ERRCODE = 'insufficient_privilege';
                END
                $$;

            DROP TRIGGER audit_logs_append_only ON audit_logs;
            CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation();
            CREATE TRIGGER audit_logs_ip_scrub_only
                BEFORE UPDATE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_allow_ip_scrub();

            GRANT UPDATE (ip_address) ON audit_logs TO sentra_app;
            CREATE POLICY audit_ip_scrub ON audit_logs
                FOR UPDATE
                USING ((school_id IS NOT NULL AND school_id = app_school_id()) OR (school_id IS NULL AND app_is_platform()))
                WITH CHECK ((school_id IS NOT NULL AND school_id = app_school_id()) OR (school_id IS NULL AND app_is_platform()));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP POLICY audit_ip_scrub ON audit_logs;
            REVOKE UPDATE (ip_address) ON audit_logs FROM sentra_app;
            DROP TRIGGER audit_logs_ip_scrub_only ON audit_logs;
            DROP TRIGGER audit_logs_no_delete ON audit_logs;
            CREATE TRIGGER audit_logs_append_only
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation();
            DROP FUNCTION audit_logs_allow_ip_scrub();
        SQL);
    }
};
