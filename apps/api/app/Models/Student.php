<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Siswa. Tidak pernah dihapus: status menutup siswa yang sudah tidak aktif agar riwayat absensi tetap utuh.
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_number
 * @property string|null $membership_id
 * @property string|null $nisn
 * @property string $full_name
 * @property Carbon|null $date_of_birth
 * @property string $status
 * @property-read Collection<int, ClassEnrollment> $enrollments
 */
class Student extends Model
{
    use BelongsToSchool, HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUSES = ['active', 'inactive', 'graduated', 'transferred'];

    protected $guarded = ['id'];

    protected $casts = ['date_of_birth' => 'date:Y-m-d', 'status_changed_at' => 'datetime'];

    /**
     * @return HasMany<ClassEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(ClassEnrollment::class)->orderBy('start_date');
    }
}
