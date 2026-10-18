<?php

namespace App\Http\Requests;

use App\Models\AcademicYear;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * POST dan PATCH /academic-years. Aktivasi punya endpoint sendiri, jadi `is_active` tidak bisa diubah di sini.
 */
class AcademicYearRequest extends SchoolRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->currentYearId();

        return [
            'name' => $this->optionalOnUpdate([
                'required', 'string', 'max:100',
                Rule::unique('academic_years', 'name')->where('school_id', $this->schoolId())->ignore($current),
            ]),
            'start_date' => $this->optionalOnUpdate(['required', 'date_format:Y-m-d']),
            'end_date' => $this->optionalOnUpdate(['required', 'date_format:Y-m-d']),
            'is_active' => ['prohibited'],
        ];
    }

    /**
     * Pada PATCH, tanggal yang tidak dikirim diambil dari data tersimpan agar urutan mulai < selesai tetap diperiksa.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                return;
            }

            $stored = $this->currentYear();
            $start = $this->input('start_date', $stored?->start_date->toDateString());
            $end = $this->input('end_date', $stored?->end_date->toDateString());

            if (is_string($start) && is_string($end) && $end <= $start) {
                $validator->errors()->add('end_date', 'Tanggal selesai harus setelah tanggal mulai.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Nama tahun ajaran sudah dipakai.',
            'start_date.date_format' => 'Tanggal mulai harus berformat TTTT-BB-HH.',
            'end_date.date_format' => 'Tanggal selesai harus berformat TTTT-BB-HH.',
            'is_active.prohibited' => 'Status aktif diubah lewat tindakan aktivasi.',
        ];
    }

    private function currentYearId(): ?string
    {
        return $this->currentYear()?->id;
    }

    private function currentYear(): ?AcademicYear
    {
        $id = $this->route('academicYear');

        return $this->isUpdate() && is_string($id) ? AcademicYear::query()->find($id) : null;
    }
}
