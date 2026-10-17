<?php

namespace App\Models;

use App\Casts\PostgresTextArray;
use App\Domain\Membership\InvitationToken;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Undangan keanggotaan. Kolom token_hash TIDAK pernah dimuat ke model (bytea berupa stream dan
 * tidak boleh bocor ke respons/log); gunakan scopeSafe() atau findByToken().
 *
 * @property string $id
 * @property string $school_id
 * @property string $email
 * @property list<string> $role_codes
 * @property string $invited_by_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property string|null $accepted_user_id
 * @property Carbon|null $revoked_at
 * @property string $delivery_status
 * @property string|null $delivery_error
 * @property Carbon $created_at
 */
class MembershipInvitation extends Model
{
    use BelongsToSchool, HasUuids;

    public const COLUMNS = [
        'id', 'school_id', 'email', 'role_codes', 'invited_by_user_id', 'expires_at', 'accepted_at',
        'accepted_user_id', 'revoked_at', 'delivery_status', 'delivery_error', 'created_at', 'updated_at',
    ];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role_codes' => PostgresTextArray::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSafe(Builder $query): void
    {
        $query->select(self::COLUMNS);
    }

    /**
     * Pencarian publik lewat fungsi SECURITY DEFINER (tanpa konteks sekolah): satu baris per hash.
     */
    public static function findByToken(string $token): ?self
    {
        if (! InvitationToken::isWellFormed($token)) {
            return null;
        }

        $row = DB::selectOne(
            'SELECT '.implode(', ', self::COLUMNS).' FROM find_invitation_by_token_hash(?::bytea)',
            [InvitationToken::hash($token)],
        );

        return $row === null ? null : (new self)->newFromBuilder((array) $row);
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    /**
     * Email tersamar untuk halaman publik: g***@contoh.id
     */
    public function maskedEmail(): string
    {
        [$local, $domain] = explode('@', $this->email, 2) + [1 => ''];

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
