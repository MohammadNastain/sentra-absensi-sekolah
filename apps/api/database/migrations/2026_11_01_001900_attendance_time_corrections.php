<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Koreksi jam masuk/pulang: event tidak pernah diubah, jadi jam hasil koreksi disimpan sebagai override pada proyeksi
 * harian dan dibaca laporan lebih dulu daripada jam event. Pengajuan koreksi boleh memakai kedua jenis baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE daily_attendance
                ADD COLUMN check_in_time_override timestamptz NULL,
                ADD COLUMN check_out_time_override timestamptz NULL;
            ALTER TABLE correction_requests DROP CONSTRAINT correction_requests_type_check;
            ALTER TABLE correction_requests ADD CONSTRAINT correction_requests_type_check
                CHECK (adjustment_type IN ('status_change', 'early_leave_decision', 'check_in_time', 'check_out_time'));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM correction_requests WHERE adjustment_type IN ('check_in_time', 'check_out_time');
            ALTER TABLE correction_requests DROP CONSTRAINT correction_requests_type_check;
            ALTER TABLE correction_requests ADD CONSTRAINT correction_requests_type_check
                CHECK (adjustment_type IN ('status_change', 'early_leave_decision'));
            ALTER TABLE daily_attendance DROP COLUMN check_in_time_override, DROP COLUMN check_out_time_override;
        SQL);
    }
};
