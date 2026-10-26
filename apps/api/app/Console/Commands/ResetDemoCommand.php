<?php

namespace App\Console\Commands;

use App\Domain\Demo\DemoMode;
use App\Domain\Demo\DemoSchoolBuilder;
use Illuminate\Console\Command;

/** Membangun ulang sekolah demo dengan data segar (dijadwalkan harian bila DEMO_ENABLED aktif). */
final class ResetDemoCommand extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Bangun ulang sekolah demo (data fiktif lengkap); sekolah demo lama dinonaktifkan';

    public function handle(DemoSchoolBuilder $builder): int
    {
        if (! DemoMode::enabled()) {
            $this->warn('DEMO_ENABLED tidak aktif; tidak ada yang dilakukan.');

            return self::SUCCESS;
        }

        $started = microtime(true);
        $code = $builder->build();
        $this->info(sprintf('Sekolah demo "%s" siap (%.1f detik).', $code, microtime(true) - $started));

        return self::SUCCESS;
    }
}
