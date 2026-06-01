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
        // Insert Weekly Summary settings
        DB::table('settings')->insert([
            [
                'key' => 'weekly_summary_enabled',
                'value' => '1',
                'type' => 'boolean',
                'label' => 'Weekly Summary Enabled',
                'description' => 'Enable or disable automatic weekly match summary reports',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'weekly_summary_day',
                'value' => '1',
                'type' => 'number',
                'label' => 'Weekly Summary Day',
                'description' => 'Day of week to send summary (1=Monday, 7=Sunday)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'weekly_summary_time',
                'value' => '10:00',
                'type' => 'string',
                'label' => 'Weekly Summary Time',
                'description' => 'Time to send summary in 24-hour format (HH:MM)',
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
            ->whereIn('key', [
                'weekly_summary_enabled',
                'weekly_summary_day',
                'weekly_summary_time',
            ])
            ->delete();
    }
};
