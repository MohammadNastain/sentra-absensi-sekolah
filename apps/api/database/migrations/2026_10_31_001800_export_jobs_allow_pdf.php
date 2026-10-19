<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Ekspor PDF (dompdf) menambah format ketiga pada export_jobs. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("ALTER TABLE export_jobs DROP CONSTRAINT export_jobs_format_check; ALTER TABLE export_jobs ADD CONSTRAINT export_jobs_format_check CHECK (format IN ('csv', 'xlsx', 'pdf'));");
    }

    public function down(): void
    {
        DB::unprepared("DELETE FROM export_jobs WHERE format = 'pdf'; ALTER TABLE export_jobs DROP CONSTRAINT export_jobs_format_check; ALTER TABLE export_jobs ADD CONSTRAINT export_jobs_format_check CHECK (format IN ('csv', 'xlsx'));");
    }
};
