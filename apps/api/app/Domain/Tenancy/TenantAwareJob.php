<?php

namespace App\Domain\Tenancy;

use App\Domain\Entitlement\EntitlementResolver;
use App\Domain\Entitlement\Feature;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Dasar semua job yang menyentuh data sekolah (ARCHITECTURE §5). Job membawa school_id, menyetel
 * konteks RLS sebelum bekerja dan memulihkannya sesudahnya. Tanpa school_id job gagal dengan
 * exception (T12), bukan berjalan dengan konteks kosong yang mengembalikan data kosong diam-diam.
 */
abstract class TenantAwareJob
{
    public function __construct(public readonly string $schoolId) {}

    final public function handle(DatabaseContext $database): void
    {
        if ($this->schoolId === '') {
            throw new LogicException(static::class.' dijalankan tanpa school_id.');
        }

        $database->runInSchool($this->schoolId, function (): void {
            // Fitur bisa dicabut setelah job diantre; periksa lagi saat dijalankan (ADR 0009 §5).
            $feature = $this->requiredFeature();
            if ($feature !== null && ! app(EntitlementResolver::class)->allows($this->schoolId, $feature)) {
                Log::warning('Job dibatalkan: fitur tidak lagi termasuk paket sekolah.', ['job' => static::class, 'school_id' => $this->schoolId, 'feature' => $feature->value]);
                $this->entitlementLost($feature);

                return;
            }

            $this->run();
        });
    }

    /** Fitur yang harus dimiliki sekolah agar job boleh berjalan; null = tidak digerbang (mis. email undangan, inti). */
    protected function requiredFeature(): ?Feature
    {
        return null;
    }

    /** Dipanggil (dalam konteks sekolah) bila fitur sudah dicabut; tandai pekerjaan agar tidak menggantung. */
    protected function entitlementLost(Feature $feature): void {}

    abstract protected function run(): void;
}
