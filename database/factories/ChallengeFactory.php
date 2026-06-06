<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Challenge>
 */
class ChallengeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $codes = ['total_kills', 'total_denies', 'total_heal', 'hero_win', 'item_win', 'last_hits', 'zero_death_win', 'fast_win'];

        $categories = [
            'total_kills' => 'accumulative',
            'total_denies' => 'accumulative',
            'total_heal' => 'accumulative',
            'hero_win' => 'accumulative',
            'item_win' => 'accumulative',
            'last_hits' => 'snapshot',
            'zero_death_win' => 'snapshot',
            'fast_win' => 'snapshot',
        ];

        $code = fake()->randomElement($codes);
        $isSnapshot = $categories[$code] === 'snapshot';

        return [
            'code' => $code,
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'category' => $categories[$code],
            'base_requirement' => $isSnapshot ? fake()->numberBetween(5, 50) : fake()->numberBetween(20, 100),
            'increment_value' => $isSnapshot ? 0 : fake()->numberBetween(5, 20),
            'max_requirement' => $isSnapshot ? fake()->numberBetween(5, 50) : fake()->numberBetween(60, 200),
            'configuration' => null,
            'is_active' => true,
            'weight' => fake()->numberBetween(1, 20),
        ];
    }
}
