<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Notifikasi dalam aplikasi untuk satu penerima di satu sekolah (ADR 0010 §8).
 *
 * @property string $id
 * @property string $school_id
 * @property string $user_id
 * @property string $type
 * @property string $title
 * @property string $body
 * @property array<string, mixed> $data
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 */
class Notification extends Model
{
    use BelongsToSchool, HasUuids;

    public const UPDATED_AT = null;

    public const TYPE_ASSIGNMENT_PUBLISHED = 'lms.assignment_published';

    public const TYPE_SUBMISSION_GRADED = 'lms.submission_graded';

    public const TYPE_SUBMISSION_RETURNED = 'lms.submission_returned';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
