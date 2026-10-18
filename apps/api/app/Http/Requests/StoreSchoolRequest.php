<?php

namespace App\Http\Requests;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSchoolRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$/', Rule::unique('schools', 'code')],
            'name' => ['required', 'string', 'max:200'],
            'npsn' => ['nullable', 'string', 'regex:/^[0-9]{8}$/', Rule::unique('schools', 'npsn')],
            'education_levels' => ['required', 'array', 'min:1'],
            'education_levels.*' => ['string', 'distinct', Rule::in(School::EDUCATION_LEVELS)],
            'timezone' => ['required', 'string', Rule::in(School::TIMEZONES)],
            'address' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'string', Rule::in([School::STATUS_TRIAL, School::STATUS_ACTIVE])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode hanya boleh huruf kecil, angka, dan tanda hubung (2–40 karakter, tidak diawali/diakhiri tanda hubung).',
            'code.unique' => 'Kode sekolah sudah dipakai.',
            'npsn.regex' => 'NPSN harus 8 digit angka.',
            'npsn.unique' => 'NPSN sudah terdaftar.',
        ];
    }
}
