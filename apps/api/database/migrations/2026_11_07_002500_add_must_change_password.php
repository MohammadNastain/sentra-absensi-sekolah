<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0010 §1 — akun siswa memakai kata sandi awal dari admin; `must_change_password` memaksa penggantian saat login
 * pertama (dan setelah admin mengatur ulang). Akun lain tidak terpengaruh (default false).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE users ADD COLUMN must_change_password boolean NOT NULL DEFAULT false');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE users DROP COLUMN must_change_password');
    }
};
