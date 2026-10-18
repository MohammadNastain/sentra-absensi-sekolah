<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Penempatan siswa di sebuah kelas pada rentang tanggal; end_date kosong = masih berlangsung.
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_id
 * @property string $class_id
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property-read SchoolClass $schoolClass
 */
class ClassEnrollment extends Model
{
    use BelongsToSchool, HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}
