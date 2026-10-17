<?php

namespace App\Models;

use App\Domain\Identity\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Keanggotaan user di satu sekolah (ADR 0004). Dilindungi RLS.
 *
 * @property string $id
 * @property string $school_id
 * @property string $user_id
 * @property string $status
 * @property string|null $status_reason
 * @property Carbon $joined_at
 * @property Carbon|null $ended_at
 * @property-read Collection<int, MembershipRole> $roles
 * @property-read School $school
 * @property-read User $user
 */
class SchoolMembership extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_ENDED = 'ended';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<MembershipRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(MembershipRole::class, 'membership_id');
    }

    /**
     * @return list<Role>
     */
    public function roleEnums(): array
    {
        return array_values($this->roles
            ->map(fn (MembershipRole $role) => Role::from($role->role_code))
            ->sortBy(fn (Role $role) => $role->value)
            ->all());
    }
}
