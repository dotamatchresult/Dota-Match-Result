<?php

namespace Database\Factories;

use App\Models\DestinationChallenge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChallengeEvent>
 */
class ChallengeEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'destination_challenge_id' => DestinationChallenge::factory(),
            'match_id' => null,
            'type' => fake()->randomElement(['assigned', 'progress', 'incremented', 'completed']),
            'value_before' => fake()->optional()->numberBetween(0, 50),
            'value_after' => fake()->optional()->numberBetween(10, 100),
            'payload' => null,
        ];
    }

    public function forMatch(int $matchId): static
    {
        return $this->state(fn (array $attributes) => [
            'match_id' => $matchId,
            'type' => 'progress',
        ]);
    }

    public function ofType(string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
        ]);
    }
}
