<?php

namespace App\Jobs;

use App\Domain\Imports\ValidateImportBatch;
use App\Domain\Tenancy\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Memvalidasi file impor di latar belakang. Tanpa retry otomatis: kegagalan dicatat pada batch
 * (status failed) dan admin mengunggah ulang.
 */
class ValidateImport extends TenantAwareJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(string $schoolId, public readonly string $batchId)
    {
        parent::__construct($schoolId);
    }

    protected function run(): void
    {
        app(ValidateImportBatch::class)->handle($this->schoolId, $this->batchId);
    }
}
