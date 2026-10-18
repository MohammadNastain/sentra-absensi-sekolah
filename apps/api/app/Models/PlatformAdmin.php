<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penanda Super Admin. Tidak memberi akses ke rute sekolah (D-21).
 *
 * @property string $user_id
 * @property string|null $created_by_user_id
 */
class PlatformAdmin extends Model
{
    public const UPDATED_AT = null;

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
