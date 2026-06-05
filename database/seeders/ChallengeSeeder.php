<?php

namespace Database\Seeders;

use App\Models\Challenge;
use Illuminate\Database\Seeder;

class ChallengeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $challenges = [
            [
                'code' => 'total_kills',
                'name' => 'Total Kills',
                'description' => 'Get {requirement} total kills across all matches today',
                'category' => 'accumulative',
                'base_requirement' => 30,
                'increment_value' => 5,
                'max_requirement' => 60,
                'configuration' => null,
                'is_active' => true,
            ],
            [
                'code' => 'total_denies',
                'name' => 'Total Denies',
                'description' => 'Get {requirement} total denies across all matches today',
                'category' => 'accumulative',
                'base_requirement' => 20,
                'increment_value' => 5,
                'max_requirement' => 40,
                'configuration' => null,
                'is_active' => true,
            ],
            [
                'code' => 'total_heal',
                'name' => 'Total Healing',
                'description' => 'Heal {requirement} total HP across all matches today',
                'category' => 'accumulative',
                'base_requirement' => 10000,
                'increment_value' => 2000,
                'max_requirement' => 20000,
                'configuration' => null,
                'is_active' => true,
            ],
            [
                'code' => 'hero_win',
                'name' => 'Hero Win',
                'description' => 'Win {requirement} game(s) with the assigned hero today',
                'category' => 'accumulative',
                'base_requirement' => 1,
                'increment_value' => 1,
                'max_requirement' => 3,
                'configuration' => null,
                'is_active' => true,
            ],
            [
                'code' => 'item_win',
                'name' => 'Item Win',
                'description' => 'Win {requirement} game(s) where someone buys {item_name}',
                'category' => 'accumulative',
                'base_requirement' => 1,
                'increment_value' => 1,
                'max_requirement' => 3,
                'configuration' => null,
                'is_active' => true,
            ],
            [
                'code' => 'last_hits',
                'name' => 'Last Hits',
                'description' => 'Get {requirement} last hits in a single match',
                'category' => 'snapshot',
                'base_requirement' => 60,
                'increment_value' => 0,
                'max_requirement' => 60,
                'configuration' => null,
                'is_active' => true,
            ],
            [
                'code' => 'zero_death_win',
                'name' => 'Zero Death Win',
                'description' => 'Win a game with 0 deaths on any hero',
                'category' => 'snapshot',
                'base_requirement' => 1,
                'increment_value' => 0,
                'max_requirement' => 1,
                'configuration' => null,
                'is_active' => true,
            ],
            [
                'code' => 'fast_win',
                'name' => 'Fast Win',
                'description' => 'Win a game in under {requirement} minutes',
                'category' => 'snapshot',
                'base_requirement' => 25,
                'increment_value' => 0,
                'max_requirement' => 25,
                'configuration' => null,
                'is_active' => true,
            ],
        ];

        foreach ($challenges as $challenge) {
            Challenge::firstOrCreate(
                ['code' => $challenge['code']],
                $challenge
            );
        }
    }
}
