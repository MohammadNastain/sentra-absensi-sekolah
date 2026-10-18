<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'sekolah-'.fake()->unique()->numerify('####'),
            'name' => 'SMP Uji '.fake()->unique()->numerify('###'),
            'education_levels' => ['SMP'],
            'timezone' => 'Asia/Jakarta',
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
