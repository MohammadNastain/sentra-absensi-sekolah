<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST dan PATCH /teachers. `membership_id` menautkan guru ke akun anggota sekolah (opsional, D-07).
 * Status hanya bisa diubah lewat PATCH.
 */
class TeacherRequest extends SchoolRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->route('teacher');
        $current = is_string($current) ? $current : null;
        $schoolId = $this->schoolId();

        return [
            'full_name' => $this->optionalOnUpdate(['required', 'string', 'max:200']),
            'employee_number' => [
                'sometimes', 'nullable', 'string', 'max:50',
                Rule::unique('teachers', 'employee_number')->where('school_id', $schoolId)->ignore($current),
            ],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            'subject_ids' => ['sometimes', 'array', 'max:10'],
            'subject_ids.*' => ['uuid', 'distinct', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'membership_id' => [
                'bail', 'sometimes', 'nullable', 'uuid',
                Rule::exists('school_memberships', 'id')->where(fn ($q) => $q->where('school_id', $schoolId)->where('status', '!=', 'ended')),
                Rule::unique('teachers', 'membership_id')->ignore($current),
            ],
            'status' => $this->isUpdate() ? ['sometimes', 'required', Rule::in(['active', 'inactive'])] : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_number.unique' => 'Nomor pegawai sudah dipakai guru lain.',
            'membership_id.exists' => 'Akun tidak ditemukan di sekolah ini atau sudah berakhir.',
            'membership_id.unique' => 'Akun ini sudah ditautkan ke guru lain.',
            'email.email' => 'Format email tidak valid.',
            'subject_ids.*.exists' => 'Mapel tidak ditemukan.',
            'status.prohibited' => 'Guru baru selalu berstatus aktif.',
        ];
    }
}
