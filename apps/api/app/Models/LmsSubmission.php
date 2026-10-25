<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Pengumpulan satu siswa untuk satu tugas (ADR 0010 §3). Waktu kirim dan keterlambatan ditentukan server.
 *
 * @property string $id
 * @property string $school_id
 * @property string $assignment_id
 * @property string $student_id
 * @property string $body
 * @property string $status
 * @property Carbon $submitted_at
 * @property bool $is_late
 * @property numeric-string|null $score
 * @property string|null $feedback
 * @property string|null $graded_by_user_id
 * @property Carbon|null $graded_at
 */
class LmsSubmission extends Model
{
    use BelongsToSchool, HasUuids;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_RETURNED = 'returned_for_revision';

    public const STATUS_GRADED = 'graded';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'graded_at' => 'datetime', 'is_late' => 'boolean'];
    }
}
