<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fungsi pembaca konteks RLS dan trigger append-only (DATABASE_DESIGN §6).
 *
 * Konteks di-reset dengan set_config(..., '', false), sehingga current_setting()
 * dapat mengembalikan string kosong. NULLIF membuat nilai kosong menjadi NULL
 * dan policy menolak semua baris (gagal tertutup), bukan melempar error cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_school_id() RETURNS uuid
                LANGUAGE sql STABLE PARALLEL SAFE
                AS $$ SELECT NULLIF(current_setting('app.school_id', true), '')::uuid $$;

            CREATE OR REPLACE FUNCTION app_user_id() RETURNS uuid
                LANGUAGE sql STABLE PARALLEL SAFE
                AS $$ SELECT NULLIF(current_setting('app.user_id', true), '')::uuid $$;

            CREATE OR REPLACE FUNCTION app_is_platform() RETURNS boolean
                LANGUAGE sql STABLE PARALLEL SAFE
                AS $$ SELECT COALESCE(current_setting('app.platform', true), '') = 'on' $$;

            CREATE OR REPLACE FUNCTION reject_append_only_mutation() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION 'Tabel % bersifat append-only: % tidak diizinkan', TG_TABLE_NAME, TG_OP
                        USING ERRCODE = 'insufficient_privilege';
                END
                $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION reject_append_only_mutation();
            DROP FUNCTION app_is_platform();
            DROP FUNCTION app_user_id();
            DROP FUNCTION app_school_id();
            SQL);
    }
};
