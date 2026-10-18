<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Satu kali unggah file impor siswa beserta statusnya (diunggah → divalidasi → di-commit).
 *
 * @property string $id
 * @property string $school_id
 * @property string $type
 * @property string $status
 * @property string $original_filename
 * @property string $storage_path
 * @property string $file_sha256
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $invalid_rows
 * @property int $committed_rows
 * @property string|null $error_message
 * @property string $uploaded_by_user_id
 * @property Carbon|null $committed_at
 * @property Carbon $created_at
 */
class ImportBatch extends Model
{
    use BelongsToSchool, HasUuids;

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_COMMITTING = 'committing';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id'];

    protected $casts = ['committed_at' => 'datetime'];
}
