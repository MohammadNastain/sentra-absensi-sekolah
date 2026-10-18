<?php

namespace App\Http\Requests;

use App\Models\Schedule;
use App\Models\SchoolClass;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * POST /schedules (menerbitkan versi baru untuk satu scope dan beberapa hari) dan PATCH /schedules/{id}
 * (hanya menutup versi atau menonaktifkannya; jam tidak bisa diubah).
 */
class ScheduleRequest extends SchoolRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->isUpdate()) {
            return [
                'effective_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'is_active' => ['sometimes', 'required', 'boolean'],
                'scope_type' => ['prohibited'], 'grade_level' => ['prohibited'], 'class_id' => ['prohibited'],
                'days' => ['prohibited'], 'day_of_week' => ['prohibited'], 'effective_from' => ['prohibited'],
                'check_in_start' => ['prohibited'], 'check_in_deadline' => ['prohibited'], 'late_grace_minutes' => ['prohibited'],
                'check_out_start' => ['prohibited'], 'scheduled_check_out' => ['prohibited'],
            ];
        }

        $scope = $this->input('scope_type');

        return [
            'scope_type' => ['required', Rule::in([Schedule::SCOPE_SCHOOL, Schedule::SCOPE_GRADE, Schedule::SCOPE_CLASS])],
            'grade_level' => $scope === Schedule::SCOPE_GRADE ? ['required', 'integer', 'between:1,13'] : ['prohibited'],
            'class_id' => $scope === Schedule::SCOPE_CLASS
                ? ['bail', 'required', 'uuid', Rule::exists('classes', 'id')->where(fn ($q) => $q->where('school_id', $this->schoolId())->where('status', SchoolClass::STATUS_ACTIVE))]
                : ['prohibited'],
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*' => ['integer', 'between:1,7', 'distinct'],
            'check_in_start' => ['required', 'date_format:H:i'],
            'check_in_deadline' => ['required', 'date_format:H:i'],
            'late_grace_minutes' => ['required', 'integer', 'between:0,120'],
            'check_out_start' => ['required', 'date_format:H:i'],
            'scheduled_check_out' => ['required', 'date_format:H:i'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'grade_level.prohibited' => 'Tingkat hanya untuk jadwal per jenjang.',
            'class_id.prohibited' => 'Kelas hanya untuk jadwal per kelas.',
            'class_id.exists' => 'Kelas tidak ditemukan atau sudah diarsipkan.',
            'days.min' => 'Pilih minimal satu hari.',
            '*.date_format' => ':attribute harus berformat JJ:MM.',
            'effective_from.date_format' => 'Tanggal berlaku harus berformat TTTT-BB-HH.',
            'effective_until.date_format' => 'Tanggal berakhir harus berformat TTTT-BB-HH.',
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->isUpdate() || $validator->errors()->hasAny(['check_in_start', 'check_in_deadline', 'check_out_start', 'scheduled_check_out'])) {
                return;
            }

            $start = $this->string('check_in_start')->toString();
            $deadline = $this->string('check_in_deadline')->toString();
            $outStart = $this->string('check_out_start')->toString();
            $out = $this->string('scheduled_check_out')->toString();

            if ($start >= $deadline) {
                $validator->errors()->add('check_in_deadline', 'Batas tepat waktu harus setelah jam mulai absen masuk.');
            }
            if ($deadline >= $outStart) {
                $validator->errors()->add('check_out_start', 'Mulai absen pulang harus setelah batas tepat waktu.');
            }
            if ($outStart > $out) {
                $validator->errors()->add('scheduled_check_out', 'Jam pulang tidak boleh sebelum mulai absen pulang.');
            }
        }];
    }
}
