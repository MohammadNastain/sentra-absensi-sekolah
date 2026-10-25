<?php

namespace App\Http\Requests;

use App\Domain\Identity\Role;
use Illuminate\Validation\Rule;

class ChangeRolesRequest extends ReasonRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::in(Role::assignableValues())],
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
