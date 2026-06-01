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
        // Insert Steam News settings
        DB::table('settings')->insert([
            [
                'key' => 'last_steam_news_check',
                'value' => null,
                'type' => 'datetime',
                'label' => 'Last Steam News Check',
                'description' => 'Timestamp of the last successful Steam news fetch',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'steam_news_fetch_count',
                'value' => '10',
                'type' => 'number',
                'label' => 'Steam News Fetch Count',
                'description' => 'Number of news items to fetch per hour (5-10 recommended)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'steam_news_enabled',
                'value' => '1',
                'type' => 'boolean',
                'label' => 'Steam News Enabled',
                'description' => 'Enable or disable automatic Steam news fetching and notifications',
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
            ->whereIn('key', ['last_steam_news_check', 'steam_news_fetch_count', 'steam_news_enabled'])
            ->delete();
    }
};
