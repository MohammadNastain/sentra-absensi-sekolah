<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Terminal gerbang. Hash token dan kode pairing tidak pernah dimuat ke model.
 *
 * @property string $id
 * @property string $school_id
 * @property string $name
 * @property string $device_type
 * @property string $status
 * @property Carbon|null $pairing_expires_at
 * @property Carbon|null $paired_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property Carbon $created_at
 */
class Device extends Model
{
    use BelongsToSchool, HasUuids;

    public const STATUS_PENDING = 'pending_pairing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    /** Terminal dianggap online bila heartbeat/aktivitas terakhir tidak lebih lama dari ini. */
    public const ONLINE_WINDOW_SECONDS = 120;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'pairing_code_hash'];

    protected function casts(): array
    {
        return ['pairing_expires_at' => 'datetime', 'paired_at' => 'datetime', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function isOnline(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::ONLINE_WINDOW_SECONDS));
    }
}
