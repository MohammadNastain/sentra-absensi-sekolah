<?php

namespace App\Http\Requests;

use App\Models\SchoolClass;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * POST dan PATCH /classes. Tahun ajaran ditentukan saat dibuat dan tidak bisa dipindah.
 * Wali kelas harus guru aktif di sekolah ini; null melepas wali kelas.
 */
class SchoolClassRequest extends SchoolRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $schoolId = $this->schoolId();
        $existing = $this->existing();
        $yearId = $existing !== null ? $existing->academic_year_id : $this->input('academic_year_id');

        return [
            'academic_year_id' => $this->isUpdate()
                ? ['prohibited']
                : ['bail', 'required', 'uuid', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
            'name' => $this->optionalOnUpdate([
                'required', 'string', 'max:100',
                Rule::unique('classes', 'name')
                    ->where('school_id', $schoolId)
                    ->where('academic_year_id', is_string($yearId) && Str::isUuid($yearId) ? $yearId : null)
                    ->ignore($existing?->id),
            ]),
            'grade_level' => $this->optionalOnUpdate(['required', 'integer', 'between:1,13']),
            'homeroom_teacher_id' => [
                'bail', 'sometimes', 'nullable', 'uuid',
                Rule::exists('teachers', 'id')->where(fn ($q) => $q->where('school_id', $schoolId)->where('status', 'active')),
            ],
            'status' => $this->isUpdate() ? ['sometimes', 'required', Rule::in([SchoolClass::STATUS_ACTIVE, SchoolClass::STATUS_ARCHIVED])] : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'academic_year_id.exists' => 'Tahun ajaran tidak ditemukan.',
            'academic_year_id.prohibited' => 'Tahun ajaran kelas tidak dapat diubah.',
            'name.unique' => 'Nama kelas sudah dipakai pada tahun ajaran ini.',
            'grade_level.between' => 'Tingkat kelas harus antara 1 dan 13.',
            'homeroom_teacher_id.exists' => 'Wali kelas harus guru aktif di sekolah ini.',
            'status.prohibited' => 'Kelas baru selalu berstatus aktif.',
        ];
    }

    private function existing(): ?SchoolClass
    {
        $id = $this->route('schoolClass');

        return $this->isUpdate() && is_string($id) ? SchoolClass::query()->find($id) : null;
    }
}
