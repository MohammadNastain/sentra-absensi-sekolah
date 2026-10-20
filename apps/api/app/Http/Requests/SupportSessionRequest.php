<?php

namespace App\Http\Requests;

/**
 * Alasan akses dukungan: lebih panjang dari alasan biasa karena membuka data sekolah (D-21).
 */
class SupportSessionRequest extends ReasonRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:10', 'max:500']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan akses dukungan wajib diisi.',
            'reason.min' => 'Alasan minimal 10 karakter: jelaskan masalah yang akan diperbaiki.',
        ];
    }
}
