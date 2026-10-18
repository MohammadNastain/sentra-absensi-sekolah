<?php

namespace App\Http\Requests;

use App\Models\Student;
use Illuminate\Validation\Rule;

/**
 * POST dan PATCH /students. Status hanya bisa diubah lewat PATCH; penempatan kelas lewat endpoint tersendiri.
 */
class StudentRequest extends SchoolRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->route('student');
        $current = is_string($current) ? $current : null;
        $schoolId = $this->schoolId();

        return [
            'student_number' => $this->optionalOnUpdate([
                'required', 'string', 'max:50',
                Rule::unique('students', 'student_number')->where('school_id', $schoolId)->ignore($current),
            ]),
            'nisn' => [
                'sometimes', 'nullable', 'string', 'regex:/^[0-9]{10}$/',
                Rule::unique('students', 'nisn')->where('school_id', $schoolId)->ignore($current),
            ],
            'full_name' => $this->optionalOnUpdate(['required', 'string', 'max:200']),
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'status' => $this->isUpdate() ? ['sometimes', 'required', Rule::in(Student::STATUSES)] : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_number.unique' => 'Nomor induk siswa sudah dipakai.',
            'nisn.regex' => 'NISN harus 10 digit angka.',
            'nisn.unique' => 'NISN sudah dipakai siswa lain.',
            'date_of_birth.before_or_equal' => 'Tanggal lahir tidak boleh di masa depan.',
            'status.prohibited' => 'Siswa baru selalu berstatus aktif.',
        ];
    }
}
