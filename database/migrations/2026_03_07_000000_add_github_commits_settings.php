<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('settings')->insert([
            [
                'key' => 'steamdb_dota2_commit_sha',
                'value' => null,
                'type' => 'string',
                'label' => 'SteamDB Dota2 Commit SHA',
                'description' => 'Latest processed commit SHA from SteamDatabase/GameTracking-Dota2',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'github_commits_enabled',
                'value' => '1',
                'type' => 'boolean',
                'label' => 'GitHub Commits Enabled',
                'description' => 'Enable or disable automatic GitHub commit fetching and notifications',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')
            ->whereIn('key', ['steamdb_dota2_commit_sha', 'github_commits_enabled'])
            ->delete();
    }
};
