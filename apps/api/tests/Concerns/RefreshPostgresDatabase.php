<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RefreshDatabase dengan migrasi sebagai sentra_owner. Test sendiri berjalan sebagai
 * sentra_app agar RLS dan REVOKE ikut teruji (TESTING_STRATEGY §3).
 */
trait RefreshPostgresDatabase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--database' => 'pgsql_owner',
        ];
    }
}
