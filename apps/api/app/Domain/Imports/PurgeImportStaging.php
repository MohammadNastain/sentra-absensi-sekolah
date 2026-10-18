<?php

namespace App\Domain\Imports;

use App\Domain\Tenancy\DatabaseContext;
use App\Models\ImportBatch;
use App\Models\School;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Menghapus staging impor yang melewati masa simpan (D-26, 30 hari): file unggahan di disk dan baris
 * `import_batches` beserta `import_rows` (cascade). Data siswa hasil commit dan audit tidak tersentuh.
 * Dijalankan per sekolah di bawah RLS; kegagalan satu sekolah tidak menghentikan sekolah lain.
 */
final class PurgeImportStaging
{
    public const RETENTION_DAYS = 30;

    public function __construct(private readonly DatabaseContext $database) {}

    /**
     * @return int jumlah batch yang dihapus
     */
    public function run(int $days = self::RETENTION_DAYS): int
    {
        $cutoff = Carbon::now()->subDays($days);

        /** @var list<string> $schoolIds */
        $schoolIds = $this->database->runAsPlatform(fn () => School::query()->pluck('id')->map(fn ($id) => (string) $id)->all());

        $purged = 0;
        foreach ($schoolIds as $schoolId) {
            try {
                $purged += $this->database->runInSchool($schoolId, fn () => $this->purgeSchool($cutoff));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $purged;
    }

    private function purgeSchool(Carbon $cutoff): int
    {
        $purged = 0;

        ImportBatch::query()->where('created_at', '<', $cutoff)->orderBy('created_at')->each(function (ImportBatch $batch) use (&$purged) {
            Storage::disk('local')->delete($batch->storage_path);
            $batch->delete();
            $purged++;
        });

        return $purged;
    }
}
