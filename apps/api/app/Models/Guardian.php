<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Orang tua/wali di satu sekolah (ADR 0011 §2). `membership_id` terisi setelah undangan diterima; surel hidup di akun, bukan di sini.
 *
 * @property string $id
 * @property string $school_id
 * @property string $full_name
 * @property string|null $phone
 * @property string|null $membership_id
 * @property string $status
 * @property Carbon $created_at
 */
class Guardian extends Model
{
    use BelongsToSchool, HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $guarded = ['id'];
}
