<?php

namespace App\Http\Requests;

use App\Domain\Attendance\AssistedAttendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pencatatan bantuan: verifikasi visual wajib dicentang, alasan dari enum, catatan wajib untuk alasan "other".
 */
class AssistedAttendanceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'student_id' => ['required', 'uuid'],
            'reason_code' => ['required', Rule::in(AssistedAttendance::REASONS)],
            'note' => ['nullable', 'string', 'max:500', 'required_if:reason_code,other'],
            'verified' => ['required', 'accepted'],
            'event_type' => ['nullable', Rule::in(['check_in', 'check_out'])],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'verified.accepted' => 'Verifikasi visual wajib dikonfirmasi.',
            'verified.required' => 'Verifikasi visual wajib dikonfirmasi.',
            'note.required_if' => 'Catatan wajib diisi untuk alasan lainnya.',
        ];
    }
}
