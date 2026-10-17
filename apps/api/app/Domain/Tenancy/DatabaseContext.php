<?php

namespace App\Domain\Tenancy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Penyetel konteks sesi PostgreSQL yang dibaca policy RLS (DATABASE_DESIGN §6):
 * app.school_id, app.user_id, dan app.platform.
 *
 * Nilai diset di level sesi (is_local = false) di luar transaksi, lalu WAJIB di-reset
 * saat request/job selesai. Koneksi persisten dan PgBouncer transaction pooling dilarang
 * selama RLS bergantung pada setting sesi (ARCHITECTURE §5).
 */
final class DatabaseContext
{
    private const SCHOOL = 'app.school_id';

    private const USER = 'app.user_id';

    private const PLATFORM = 'app.platform';

    private const IN_FAILED_TRANSACTION = '25P02';

    public function setUser(?string $userId): void
    {
        $this->set(self::USER, $userId ?? '');
    }

    public function setSchool(?string $schoolId): void
    {
        $this->set(self::SCHOOL, $schoolId ?? '');
    }

    public function setPlatform(bool $enabled): void
    {
        $this->set(self::PLATFORM, $enabled ? 'on' : '');
    }

    public function schoolId(): ?string
    {
        return $this->get(self::SCHOOL);
    }

    public function userId(): ?string
    {
        return $this->get(self::USER);
    }

    public function reset(): void
    {
        $this->connection()->select(
            'SELECT set_config(?, \'\', false), set_config(?, \'\', false), set_config(?, \'\', false)',
            [self::SCHOOL, self::USER, self::PLATFORM],
        );
    }

    /**
     * Menjalankan $callback dalam konteks sekolah tertentu lalu memulihkan konteks sebelumnya.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runInSchool(string $schoolId, callable $callback): mixed
    {
        $previous = $this->schoolId();
        $this->setSchool($schoolId);

        try {
            return $callback();
        } finally {
            $this->restore(fn () => $this->setSchool($previous));
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAsPlatform(callable $callback): mixed
    {
        $previous = $this->get(self::PLATFORM) === 'on';
        $this->setPlatform(true);

        try {
            return $callback();
        } finally {
            $this->restore(fn () => $this->setPlatform($previous));
        }
    }

    /**
     * Bila transaksi sudah gagal (SQLSTATE 25P02), PostgreSQL akan membatalkan SET saat rollback,
     * jadi pemulihan dilewati agar exception asli dari callback tidak tertutupi.
     */
    private function restore(callable $restore): void
    {
        try {
            $restore();
        } catch (QueryException $e) {
            if ($e->getCode() !== self::IN_FAILED_TRANSACTION) {
                throw $e;
            }
        }
    }

    private function set(string $key, string $value): void
    {
        $this->connection()->select('SELECT set_config(?, ?, false)', [$key, $value]);
    }

    private function get(string $key): ?string
    {
        $value = $this->connection()->scalar('SELECT current_setting(?, true)', [$key]);

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection();
    }
}
