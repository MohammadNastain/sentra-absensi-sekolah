<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Identitas global (ADR 0004). Tidak memiliki school_id; akses sekolah lewat keanggotaan.
 *
 * @property string $id
 * @property string $email
 * @property string $name
 * @property string $status
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $last_login_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, TwoFactorAuthenticatable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasConfirmedTwoFactor(): bool
    {
        // Atribut mentah: model yang baru dibuat belum tentu memuat kolom 2FA (strict mode).
        return ($this->attributes['two_factor_secret'] ?? null) !== null
            && ($this->attributes['two_factor_confirmed_at'] ?? null) !== null;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->platformAdmin()->exists();
    }

    /**
     * @return HasOne<PlatformAdmin, $this>
     */
    public function platformAdmin(): HasOne
    {
        return $this->hasOne(PlatformAdmin::class);
    }

    /**
     * Terbaca hanya bila konteks RLS mengizinkan (konteks user ini atau sekolah terkait).
     *
     * @return HasMany<SchoolMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(SchoolMembership::class);
    }
}
