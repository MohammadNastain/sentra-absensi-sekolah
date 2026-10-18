<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\PasswordRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

final class UpdateUserPassword implements UpdatesUserPasswords
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => PasswordRules::forNewPassword(),
        ], [
            'current_password.current_password' => 'Password saat ini tidak sesuai.',
        ])->validate();

        $user->forceFill(['password' => $input['password']])->save();
    }
}
