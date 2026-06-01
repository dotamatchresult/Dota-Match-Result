<?php

namespace Database\Factories;

use App\Models\Destination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Destination>
 */
class DestinationFactory extends Factory
{
    protected $model = Destination::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->lexify('destination_??????'),
            'name' => $this->faker->optional()->words(2, true),
            'main_bot_token' => $this->faker->optional()->sha1,
            'ai_bot_token' => $this->faker->optional()->sha1,
            'target' => $this->faker->optional()->numerify('62###########'),
        ];
    }

    public function whatsapp(): static
    {
        return $this->state(fn () => [
            'code' => Destination::CODE_WHATSAPP,
            'name' => 'WhatsApp',
        ]);
    }

    public function telegram(): static
    {
        return $this->state(fn () => [
            'code' => Destination::CODE_TELEGRAM,
            'name' => 'Telegram',
        ]);
    }
}
