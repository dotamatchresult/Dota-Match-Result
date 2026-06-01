<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SteamNews>
 */
class SteamNewsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gid' => fake()->unique()->numerify('####################'),
            'title' => fake()->sentence(),
            'url' => fake()->url(),
            'author' => fake()->name(),
            'contents' => '[p]'.fake()->paragraphs(3, true).'[/p]',
            'feedname' => 'steam_community_announcements',
            'published_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'notified_at' => fake()->optional()->dateTimeBetween('-29 days', 'now'),
            'tags' => fake()->randomElements(['patchnotes', 'mod_reviewed', 'update', 'announcement'], fake()->numberBetween(0, 3)),
        ];
    }
}
