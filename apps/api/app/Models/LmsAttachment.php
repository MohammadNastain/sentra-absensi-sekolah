<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Metadata lampiran LMS (ADR 0010 §5). Berkas fisik ada di disk privat `lms` dengan kunci acak `storage_key`.
 *
 * @property string $id
 * @property string $school_id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $original_name
 * @property string $storage_key
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $uploaded_by_user_id
 * @property Carbon $created_at
 */
class LmsAttachment extends Model
{
    use BelongsToSchool, HasUuids;

    public const UPDATED_AT = null;

    public const OWNER_MATERIAL = 'material';

    protected $guarded = ['id'];
}
