<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dipakai oleh PATCH /platform/schools/{school} dan PATCH /schools/{school}.
 * Kode dan status tidak dapat diubah di sini: kode ada di URL, status lewat suspend/activate.
 */
class UpdateSchoolRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $school = $this->route('school');
        $current = $school instanceof School ? $school : app(TenantContext::class)->school();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'npsn' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{8}$/', Rule::unique('schools', 'npsn')->ignore($current->id)],
            'education_levels' => ['sometimes', 'required', 'array', 'min:1'],
            'education_levels.*' => ['string', 'distinct', Rule::in(School::EDUCATION_LEVELS)],
            'timezone' => ['sometimes', 'required', 'string', Rule::in(School::TIMEZONES)],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'code' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'npsn.regex' => 'NPSN harus 8 digit angka.',
            'npsn.unique' => 'NPSN sudah terdaftar.',
            'code.prohibited' => 'Kode sekolah tidak dapat diubah.',
            'status.prohibited' => 'Status diubah lewat tindakan tangguhkan/aktifkan.',
        ];
    }
}
