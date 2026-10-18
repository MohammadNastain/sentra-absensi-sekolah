<?php

namespace App\Models;

use App\Casts\PostgresTextArray;
use Database\Factories\SchoolFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant. Tabel ini tidak memakai RLS; akses diatur aplikasi.
 *
 * @property string $id
 * @property string $code
 * @property string|null $npsn
 * @property string $name
 * @property list<string> $education_levels
 * @property string $timezone
 * @property string|null $address
 * @property string $status
 */
class School extends Model
{
    /** @use HasFactory<SchoolFactory> */
    use HasFactory, HasUuids;

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_TERMINATED = 'terminated';

    public const EDUCATION_LEVELS = ['SD', 'MI', 'SMP', 'MTs', 'SMA', 'SMK', 'MA', 'lainnya'];

    public const TIMEZONES = ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'];

    protected $fillable = ['code', 'npsn', 'name', 'education_levels', 'timezone', 'address'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'education_levels' => PostgresTextArray::class,
        ];
    }

    /**
     * Sekolah dapat diakses anggotanya hanya saat trial/active.
     */
    public function isAccessible(): bool
    {
        return in_array($this->status, [self::STATUS_TRIAL, self::STATUS_ACTIVE], true);
    }

    /**
     * Route model binding memakai kode sekolah (mis. /schools/smpn-1-contoh), bukan UUID.
     */
    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
