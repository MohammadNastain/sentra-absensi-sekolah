<?php

namespace Database\Factories;

use App\Domain\Tenancy\DatabaseContext;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

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

    /** Sekolah selalu punya langganan; default `legacy` (semua fitur rilis) seperti di produksi (ADR 0009 §8). */
    public function configure(): static
    {
        return $this->afterCreating(function (School $school): void {
            app(DatabaseContext::class)->runAsPlatform(fn () => DB::table('school_subscriptions')->insert([
                'school_id' => $school->id, 'plan_code' => 'legacy', 'status' => 'active',
            ]));
        });
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
