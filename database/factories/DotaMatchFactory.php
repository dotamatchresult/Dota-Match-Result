<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DotaMatch>
 */
class DotaMatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $memberId = fake()->numberBetween(1, 10);
        $accountId = 52079950 + $memberId;

        return [
            'match_id' => fake()->unique()->numerify('########'),
            'match_data' => [
                'radiant_win' => fake()->boolean(),
                'game_mode' => fake()->numberBetween(1, 23),
                'duration' => fake()->numberBetween(1200, 3600),
                'radiant_score' => fake()->numberBetween(10, 50),
                'dire_score' => fake()->numberBetween(10, 50),
                'players' => [
                    [
                        'account_id' => $accountId,
                        'player_slot' => 0,
                        'hero_id' => fake()->numberBetween(1, 150),
                        'kills' => fake()->numberBetween(0, 20),
                        'deaths' => fake()->numberBetween(0, 20),
                        'assists' => fake()->numberBetween(0, 30),
                    ],
                ],
            ],
            'members' => [
                $memberId,
            ],
            'notified_at' => null,
        ];
    }
}
