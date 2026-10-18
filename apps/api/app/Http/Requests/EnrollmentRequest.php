<?php

namespace App\Http\Requests;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * POST /students/{student}/enrollments — masuk atau pindah kelas. Kelas harus aktif di sekolah ini dan
 * tanggal mulai harus jatuh di dalam tahun ajaran kelas tersebut.
 */
class EnrollmentRequest extends SchoolRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'class_id' => [
                'bail', 'required', 'uuid',
                Rule::exists('classes', 'id')->where(fn ($q) => $q->where('school_id', $this->schoolId())->where('status', SchoolClass::STATUS_ACTIVE)),
            ],
            'start_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['class_id.exists' => 'Kelas tidak ditemukan atau sudah diarsipkan.'];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $class = SchoolClass::query()->find($this->string('class_id')->toString());
            $year = $class === null ? null : AcademicYear::query()->find($class->academic_year_id);
            $start = $this->string('start_date')->toString();

            if ($year !== null && ($start < $year->start_date->toDateString() || $start > $year->end_date->toDateString())) {
                $validator->errors()->add('start_date', 'Tanggal mulai harus berada dalam tahun ajaran kelas ('.$year->start_date->toDateString().' s.d. '.$year->end_date->toDateString().').');
            }
        }];
    }
}
