<?php

namespace Database\Factories;

use App\Models\DestinationChallenge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChallengeNotification>
 */
class ChallengeNotificationFactory extends Factory
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
            'type' => fake()->randomElement(['assigned_announcement', 'completed', 'failed_review', 'progress_milestone']),
            'scheduled_at' => null,
            'sent_at' => null,
            'status' => 'pending',
            'payload' => null,
        ];
    }

    public function scheduled(string $time): static
    {
        return $this->state(fn (array $attributes) => [
            'scheduled_at' => $time,
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }
}
