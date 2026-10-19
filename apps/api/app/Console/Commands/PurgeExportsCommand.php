<?php

namespace App\Console\Commands;

use App\Domain\Attendance\ManageExports;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\School;
use Illuminate\Console\Command;

final class PurgeExportsCommand extends Command
{
    protected $signature = 'exports:purge';

    protected $description = 'Hapus berkas ekspor laporan yang sudah kedaluwarsa (retensi 7 hari)';

    public function handle(DatabaseContext $database, ManageExports $exports): int
    {
        /** @var list<School> $schools */
        $schools = $database->runAsPlatform(fn () => School::query()->get()->all());
        $total = 0;
        foreach ($schools as $school) {
            try {
                $total += $database->runInSchool($school->id, fn () => $exports->purgeExpired($school->id));
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("Berkas ekspor dihapus: {$total}");

        return self::SUCCESS;
    }
}
