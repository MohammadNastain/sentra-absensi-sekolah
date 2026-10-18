<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Hari libur sekolah (rentang tanggal, inklusif). Pada hari libur tidak ada sesi absensi (D-24: MVP berlaku untuk seluruh sekolah).
 *
 * @property string $id
 * @property string $school_id
 * @property string $name
 * @property Carbon $start_date
 * @property Carbon $end_date
 */
class Holiday extends Model
{
    use BelongsToSchool, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }
}
