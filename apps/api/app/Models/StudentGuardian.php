<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tautan wali ke anak (ADR 0011 §2). Satu wali utama per siswa; maksimal 4 wali per siswa ditegakkan aplikasi.
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_id
 * @property string $guardian_id
 * @property string $relationship
 * @property bool $is_primary
 * @property Carbon $created_at
 */
class StudentGuardian extends Model
{
    use BelongsToSchool, HasUuids;

    public const UPDATED_AT = null;

    public const MAX_PER_STUDENT = 4;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
