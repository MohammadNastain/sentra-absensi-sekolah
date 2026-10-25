<?php

namespace App\Http\Requests;

use App\Domain\Identity\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteMemberRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::in(Role::assignableValues())],
        ];
    }

    public function email(): string
    {
        return (string) $this->validated('email');
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
