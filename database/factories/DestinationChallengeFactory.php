<?php

namespace Database\Factories;

use App\Models\Challenge;
use App\Models\Destination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DestinationChallenge>
 */
class DestinationChallengeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'destination_id' => Destination::factory(),
            'challenge_id' => Challenge::factory(),
            'assigned_date' => now()->toDateString(),
            'status' => 'active',
            'current_requirement' => fake()->numberBetween(10, 100),
            'progress_data' => null,
            'failed_days' => 0,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function withProgress(array $progressData): static
    {
        return $this->state(fn (array $attributes) => [
            'progress_data' => $progressData,
        ]);
    }
}
