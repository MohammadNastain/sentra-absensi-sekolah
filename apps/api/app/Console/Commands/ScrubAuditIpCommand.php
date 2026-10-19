<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\DatabaseContext;
use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retensi alamat IP audit (D-26: 90 hari). Hanya kolom `ip_address` yang dikosongkan; baris audit tetap utuh
 * (trigger `audit_logs_ip_scrub_only` menolak perubahan lain).
 */
final class ScrubAuditIpCommand extends Command
{
    protected $signature = 'audit:scrub-ip {--days= : Umur minimum dalam hari (bawaan: sentra.audit_ip_retention_days)}';

    protected $description = 'Kosongkan alamat IP pada audit log yang lebih tua dari masa retensi (90 hari)';

    public function handle(DatabaseContext $database): int
    {
        $days = (int) ($this->option('days') ?? config('sentra.audit_ip_retention_days'));
        if ($days < 1) {
            $this->error('Masa retensi harus minimal 1 hari.');

            return self::FAILURE;
        }

        $total = $database->runAsPlatform(fn () => $this->scrub(null, $days));
        /** @var list<School> $schools */
        $schools = $database->runAsPlatform(fn () => School::query()->get()->all());
        foreach ($schools as $school) {
            try {
                $total += $database->runInSchool($school->id, fn () => $this->scrub($school->id, $days));
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("Alamat IP audit dikosongkan: {$total}");

        return self::SUCCESS;
    }

    private function scrub(?string $schoolId, int $days): int
    {
        $total = 0;
        do {
            $count = DB::update(
                'UPDATE audit_logs SET ip_address = NULL WHERE id IN (
                    SELECT id FROM audit_logs WHERE school_id IS NOT DISTINCT FROM ? AND ip_address IS NOT NULL AND created_at < now() - make_interval(days => ?) LIMIT 5000
                 )',
                [$schoolId, $days],
            );
            $total += $count;
        } while ($count > 0);

        return $total;
    }
}
