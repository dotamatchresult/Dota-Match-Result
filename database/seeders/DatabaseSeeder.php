<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\HeroService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::create([
            'name' => 'Admin User',
            'email' => 'admin@domain.com',
            'password' => Hash::make('secret'),
        ]);

        $this->call([
            MemberSeeder::class,
            SettingSeeder::class,
            DestinationSeeder::class,
            ChallengeSeeder::class,
        ]);

        // Sync heroes from OpenDota API
        $this->command->info('Syncing heroes from OpenDota API...');
        $heroService = app(HeroService::class);
        $success = $heroService->syncHeroes();

        if ($success) {
            $this->command->info('Heroes synced successfully!');
        } else {
            $this->command->warn('Failed to sync heroes. You can manually sync them later from Settings page.');
        }
    }
}
