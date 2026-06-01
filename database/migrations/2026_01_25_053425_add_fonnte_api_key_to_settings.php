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
            'key' => 'fonnte_api_key',
            'value' => config('services.fonnte.api_key'),
            'type' => 'string',
            'label' => 'Fonnte API Key',
            'description' => 'API key for Fonnte WhatsApp service. Leave empty to use FONNTE_API_KEY from .env file.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->where('key', 'fonnte_api_key')->delete();
    }
};
