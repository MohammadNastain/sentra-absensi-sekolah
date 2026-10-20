<?php

namespace App\Http\Requests;

use App\Domain\Identity\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

class SetMemberPasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['password' => ['required', 'string', PasswordRules::default()]];
    }
}
