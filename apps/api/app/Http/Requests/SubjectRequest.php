<?php

namespace App\Http\Requests;

/**
 * POST dan PATCH /subjects. Keunikan nama (tanpa membedakan huruf besar/kecil) dijaga indeks database dan
 * diterjemahkan menjadi 409 DUPLICATE_VALUE.
 */
class SubjectRequest extends SchoolRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100', 'regex:/\S/u']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.regex' => 'Nama mapel tidak boleh kosong.'];
    }
}
