<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kelas (rombongan belajar) pada satu tahun ajaran. Disebut SchoolClass karena `Class` kata terpesan PHP.
 *
 * @property string $id
 * @property string $school_id
 * @property string $academic_year_id
 * @property string $name
 * @property int $grade_level
 * @property string|null $homeroom_teacher_id
 * @property string $status
 * @property-read AcademicYear $academicYear
 * @property-read Teacher|null $homeroomTeacher
 */
class SchoolClass extends Model
{
    use BelongsToSchool, HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'classes';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function homeroomTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'homeroom_teacher_id');
    }
}
