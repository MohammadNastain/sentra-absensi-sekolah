<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tugas per kelas-mapel (ADR 0010 §3–§4). `draft` hanya terlihat penulis/admin; `closed` tidak menerima pengumpulan baru.
 *
 * @property string $id
 * @property string $school_id
 * @property string $class_id
 * @property string $subject_id
 * @property string $author_user_id
 * @property string $title
 * @property string $instructions
 * @property Carbon|null $due_at
 * @property numeric-string $max_score
 * @property bool $accept_late
 * @property string $status
 * @property Carbon|null $published_at
 * @property Carbon $created_at
 */
class LmsAssignment extends Model
{
    use BelongsToSchool, HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CLOSED = 'closed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'published_at' => 'datetime', 'accept_late' => 'boolean'];
    }
}
