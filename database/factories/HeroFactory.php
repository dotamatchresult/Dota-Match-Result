<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Hero>
 */
class HeroFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hero_id' => fake()->unique()->numberBetween(1, 150),
            'name' => 'npc_dota_hero_'.fake()->word(),
            'localized_name' => fake()->name(),
        ];
    }
}
