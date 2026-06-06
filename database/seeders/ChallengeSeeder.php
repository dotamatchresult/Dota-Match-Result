<?php

namespace Database\Seeders;

use App\Models\Challenge;
use App\Support\DailyChallenge\ChallengeCatalog;
use Illuminate\Database\Seeder;

class ChallengeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Sources challenge definitions from the canonical ChallengeCatalog.
     * Safe to run multiple times — uses upsert-like behavior via updateOrCreate.
     */
    public function run(): void
    {
        foreach (ChallengeCatalog::definitions() as $definition) {
            Challenge::updateOrCreate(
                ['code' => $definition['code']],
                $definition,
            );
        }
    }
}
