<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu jam mengajar: guru, mata pelajaran, dan kelas pada hari (1 = Senin ... 7 = Minggu) dan rentang jam tertentu.
 *
 * @property string $id
 * @property string $school_id
 * @property string $class_id
 * @property string $subject_id
 * @property string $teacher_id
 * @property int $day_of_week
 * @property string $start_time
 * @property string $end_time
 * @property-read SchoolClass $schoolClass
 * @property-read Subject $subject
 * @property-read Teacher $teacher
 */
class TeachingSlot extends Model
{
    use BelongsToSchool, HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['day_of_week' => 'integer'];

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
