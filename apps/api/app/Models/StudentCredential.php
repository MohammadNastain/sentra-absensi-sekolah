<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Kredensial absensi siswa (QR/RFID). Hash nilai tidak pernah dimuat ke model.
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_id
 * @property string $credential_type
 * @property string $status
 * @property string|null $status_reason
 * @property Carbon $issued_at
 * @property Carbon|null $blocked_at
 * @property Carbon|null $revoked_at
 * @property string|null $replaces_credential_id
 */
class StudentCredential extends Model
{
    use BelongsToSchool, HasUuids;

    public const TYPE_QR = 'qr';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_REVOKED = 'revoked';

    protected $guarded = ['id'];

    protected $hidden = ['value_hash'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'blocked_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
