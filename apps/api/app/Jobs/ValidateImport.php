<?php

namespace App\Jobs;

use App\Domain\Entitlement\Feature;
use App\Domain\Imports\ImportKinds;
use App\Domain\Imports\ValidateImportBatch;
use App\Domain\Tenancy\TenantAwareJob;
use App\Models\ImportBatch;
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

    protected function requiredFeature(): ?Feature
    {
        $type = ImportBatch::query()->whereKey($this->batchId)->value('type');

        return is_string($type) ? ImportKinds::feature($type) : null;
    }

    protected function entitlementLost(Feature $feature): void
    {
        ImportBatch::query()->whereKey($this->batchId)->update([
            'status' => ImportBatch::STATUS_FAILED,
            'error_message' => "Fitur \"{$feature->label()}\" tidak lagi termasuk paket sekolah.",
        ]);
    }

    protected function run(): void
    {
        app(ValidateImportBatch::class)->handle($this->schoolId, $this->batchId);
    }
}
