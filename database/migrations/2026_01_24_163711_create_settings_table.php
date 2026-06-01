<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Insert default settings
        \Illuminate\Support\Facades\DB::table('settings')->insert([
            [
                'key' => 'fonnte_phone_number',
                'value' => '',
                'type' => 'string',
                'label' => 'Fonnte Phone Number',
                'description' => 'The WhatsApp phone number to send notifications to (e.g., 628123456789)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'minimum_match_date',
                'value' => '2026-01-24 17:00:00',
                'type' => 'datetime',
                'label' => 'Minimum Match Date',
                'description' => 'Only fetch matches played on or after this date. Leave empty to fetch all matches.',
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
        Schema::dropIfExists('settings');
    }
};
