<?php

namespace App\Http\Requests;

use App\Domain\Identity\PasswordRules;
use App\Domain\Identity\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Membuat akun anggota langsung oleh Admin Sekolah. Peran terminal tidak dapat dipilih: perangkat tidak memakai akun.
 */
class CreateMemberAccountRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', PasswordRules::default()],
            'employee_number' => ['nullable', 'string', 'max:50'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::in([Role::SchoolAdmin->value, Role::Principal->value, Role::HomeroomTeacher->value, Role::DutyOfficer->value])],
        ];
    }

    /**
     * @return non-empty-list<Role>
     */
    public function roles(): array
    {
        /** @var non-empty-list<string> $codes */
        $codes = $this->validated('roles');

        return array_map(fn (string $code) => Role::from($code), $codes);
    }
}
