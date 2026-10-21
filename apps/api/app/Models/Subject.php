<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Mata pelajaran. Nama unik per sekolah tanpa membedakan huruf besar/kecil.
 *
 * @property string $id
 * @property string $school_id
 * @property string $name
 */
class Subject extends Model
{
    use BelongsToSchool, HasUuids;

    protected $guarded = ['id'];

    /**
     * @return BelongsToMany<Teacher, $this>
     */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(Teacher::class, 'teacher_subjects');
    }
}
