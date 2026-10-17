<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Identitas global (ADR 0004, TAHAP_1_FONDASI §2). Tanpa school_id dan tanpa RLS:
 * data user sekolah dibaca melalui keanggotaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE users (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                email citext NOT NULL,
                name text NOT NULL,
                password text NOT NULL,
                status text NOT NULL DEFAULT 'active',
                email_verified_at timestamptz NULL,
                two_factor_secret text NULL,
                two_factor_recovery_codes text NULL,
                two_factor_confirmed_at timestamptz NULL,
                remember_token text NULL,
                last_login_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT users_email_unique UNIQUE (email),
                CONSTRAINT users_status_check CHECK (status IN ('active', 'disabled')),
                CONSTRAINT users_name_not_blank CHECK (btrim(name) <> ''),
                CONSTRAINT users_two_factor_confirmed_requires_secret
                    CHECK (two_factor_confirmed_at IS NULL OR two_factor_secret IS NOT NULL)
            );

            CREATE TABLE platform_admins (
                user_id uuid PRIMARY KEY REFERENCES users (id),
                created_by_user_id uuid NULL REFERENCES users (id),
                created_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE password_reset_tokens (
                email citext PRIMARY KEY,
                token text NOT NULL,
                created_at timestamptz NULL
            );

            CREATE TABLE failed_jobs (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid text NOT NULL UNIQUE,
                connection text NOT NULL,
                queue text NOT NULL,
                payload text NOT NULL,
                exception text NOT NULL,
                failed_at timestamptz NOT NULL DEFAULT now()
            );
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE failed_jobs;
            DROP TABLE password_reset_tokens;
            DROP TABLE platform_admins;
            DROP TABLE users;
            SQL);
    }
};
