<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tahun ajaran sebuah sekolah. Paling banyak satu yang aktif; rentang tanggal tidak boleh tumpang tindih.
 *
 * @property string $id
 * @property string $school_id
 * @property string $name
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property bool $is_active
 */
class AcademicYear extends Model
{
    use BelongsToSchool, HasUuids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
