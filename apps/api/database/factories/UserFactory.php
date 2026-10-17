<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public const PASSWORD = 'kata-sandi-uji-123';

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(self::PASSWORD),
            'status' => User::STATUS_ACTIVE,
            'remember_token' => Str::random(10),
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['status' => User::STATUS_DISABLED]);
    }

    /**
     * 2FA TOTP yang sudah dikonfirmasi. Secret mentah dapat diambil dengan decrypt($user->two_factor_secret).
     */
    public function withConfirmedTwoFactor(): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => encrypt((new Google2FA)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode([Str::random(10).'-'.Str::random(10)])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
