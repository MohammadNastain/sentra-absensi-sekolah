<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu baris jadwal untuk satu scope (sekolah/jenjang/kelas) dan satu hari dalam seminggu (ISO 1=Senin … 7=Minggu).
 * Jam tidak diubah setelah dibuat; perubahan = versi baru dengan effective_from baru.
 *
 * @property string $id
 * @property string $school_id
 * @property string $scope_type
 * @property int|null $grade_level
 * @property string|null $class_id
 * @property int $day_of_week
 * @property string $check_in_start
 * @property string $check_in_deadline
 * @property int $late_grace_minutes
 * @property string $check_out_start
 * @property string $scheduled_check_out
 * @property Carbon $effective_from
 * @property Carbon|null $effective_until
 * @property bool $is_active
 * @property-read SchoolClass|null $schoolClass
 */
class Schedule extends Model
{
    use BelongsToSchool, HasUuids;

    public const SCOPE_SCHOOL = 'school';

    public const SCOPE_GRADE = 'grade_level';

    public const SCOPE_CLASS = 'class';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}
