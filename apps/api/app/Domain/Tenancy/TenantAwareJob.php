<?php

namespace App\Domain\Tenancy;

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

        $database->runInSchool($this->schoolId, $this->run(...));
    }

    abstract protected function run(): void;
}
