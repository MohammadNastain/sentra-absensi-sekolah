<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Materi pembelajaran per kelas-mapel (ADR 0010 §3). Isi teks polos; `draft` hanya terlihat penulis dan admin.
 *
 * @property string $id
 * @property string $school_id
 * @property string $class_id
 * @property string $subject_id
 * @property string $author_user_id
 * @property string $title
 * @property string $body
 * @property string $status
 * @property Carbon|null $published_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class LmsMaterial extends Model
{
    use BelongsToSchool, HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
