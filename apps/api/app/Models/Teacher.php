<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Guru. Tidak semua guru punya akun (D-07): `membership_id` terisi hanya bila guru juga anggota sekolah.
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $membership_id
 * @property string $full_name
 * @property string|null $employee_number
 * @property string|null $email
 * @property string $status
 * @property-read SchoolMembership|null $membership
 */
class Teacher extends Model
{
    use BelongsToSchool, HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<SchoolMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(SchoolMembership::class, 'membership_id');
    }

    /**
     * @return BelongsToMany<Subject, $this>
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'teacher_subjects');
    }
}
