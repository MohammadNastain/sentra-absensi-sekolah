<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST dan PATCH /teaching-slots. Kelas harus aktif, guru aktif, dan mapel milik sekolah ini. Hari 1 (Senin)
 * sampai 7 (Minggu); jam berformat JJ:MM. Urutan jam pada PATCH diperiksa lagi terhadap nilai tersimpan.
 */
class TeachingSlotRequest extends SchoolRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $schoolId = $this->schoolId();

        return [
            'class_id' => $this->optionalOnUpdate([
                'bail', 'required', 'uuid',
                Rule::exists('classes', 'id')->where(fn ($q) => $q->where('school_id', $schoolId)->where('status', 'active')),
            ]),
            'subject_id' => $this->optionalOnUpdate([
                'bail', 'required', 'uuid',
                Rule::exists('subjects', 'id')->where('school_id', $schoolId),
            ]),
            'teacher_id' => $this->optionalOnUpdate([
                'bail', 'required', 'uuid',
                Rule::exists('teachers', 'id')->where(fn ($q) => $q->where('school_id', $schoolId)->where('status', 'active')),
            ]),
            'day_of_week' => $this->optionalOnUpdate(['required', 'integer', 'between:1,7']),
            'start_time' => $this->optionalOnUpdate(['required', 'date_format:H:i']),
            'end_time' => $this->optionalOnUpdate(['required', 'date_format:H:i', ...($this->isUpdate() ? [] : ['after:start_time'])]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'class_id.exists' => 'Kelas tidak ditemukan atau sudah diarsipkan.',
            'subject_id.exists' => 'Mapel tidak ditemukan.',
            'teacher_id.exists' => 'Guru tidak ditemukan atau tidak aktif.',
            'day_of_week.between' => 'Hari harus 1 (Senin) sampai 7 (Minggu).',
            'start_time.date_format' => 'Jam mulai harus berformat JJ:MM.',
            'end_time.date_format' => 'Jam selesai harus berformat JJ:MM.',
            'end_time.after' => 'Jam selesai harus setelah jam mulai.',
        ];
    }
}
