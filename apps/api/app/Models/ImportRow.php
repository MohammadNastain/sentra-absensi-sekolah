<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris file impor: isi asli (`payload`), hasil normalisasi, dan kesalahan validasinya.
 *
 * @property string $id
 * @property string $school_id
 * @property string $batch_id
 * @property int $row_number
 * @property array<string, string|null> $payload
 * @property array<string, mixed>|null $normalized
 * @property string $status
 * @property list<array{field: string, message: string}> $errors
 */
class ImportRow extends Model
{
    use BelongsToSchool, HasUuids;

    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_SKIPPED = 'skipped';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'normalized' => 'array', 'errors' => 'array'];
}
