<?php

namespace App\Console\Commands;

use App\Domain\Imports\PurgeImportStaging;
use Illuminate\Console\Command;

final class PurgeImportStagingCommand extends Command
{
    protected $signature = 'imports:purge {--days=30 : Umur minimal batch (hari) sebelum dihapus}';

    protected $description = 'Hapus staging impor (file dan baris) yang melewati masa simpan (D-26)';

    public function handle(PurgeImportStaging $purge): int
    {
        $days = max(1, (int) $this->option('days'));
        $this->info("Staging impor dihapus: {$purge->run($days)} batch (lebih tua dari {$days} hari).");

        return self::SUCCESS;
    }
}
