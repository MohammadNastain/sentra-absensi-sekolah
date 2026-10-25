<?php

namespace App\Jobs;

use App\Domain\Attendance\ManageExports;
use App\Domain\Entitlement\Feature;
use App\Domain\Tenancy\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Membuat berkas ekspor laporan di antrean berprioritas rendah agar tidak menghambat scan. Kegagalan dicatat pada
 * baris ekspor (status failed); tanpa retry otomatis.
 */
class GenerateReportExport extends TenantAwareJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(string $schoolId, public readonly string $exportId)
    {
        parent::__construct($schoolId);
        $this->onQueue('exports');
    }

    protected function requiredFeature(): ?Feature
    {
        return Feature::AttendanceReports;
    }

    protected function entitlementLost(Feature $feature): void
    {
        app(ManageExports::class)->failForEntitlement($this->schoolId, $this->exportId);
    }

    protected function run(): void
    {
        app(ManageExports::class)->generate($this->schoolId, $this->exportId);
    }
}
