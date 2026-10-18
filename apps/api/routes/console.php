<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retensi staging impor (D-26): harian, tanpa tumpang tindih antar proses.
Schedule::command('imports:purge')->dailyAt('02:30')->withoutOverlapping();

// Penutupan hari absensi (ADR 0005 §5): tiap jam; idempoten, jadi aman diulang. Aturan alpa baru berlaku setelah cutoff sekolah.
Schedule::command('attendance:close-day')->hourly()->withoutOverlapping();
