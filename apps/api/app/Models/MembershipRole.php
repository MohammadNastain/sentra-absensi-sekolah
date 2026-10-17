<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Peran per keanggotaan. PK komposit (membership_id, role_code): tidak ada operasi update,
 * hanya insert & delete lewat query builder.
 *
 * @property string $school_id
 * @property string $membership_id
 * @property string $role_code
 * @property string|null $granted_by_user_id
 * @property Carbon $granted_at
 */
class MembershipRole extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'membership_id';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SchoolMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(SchoolMembership::class, 'membership_id');
    }
}
