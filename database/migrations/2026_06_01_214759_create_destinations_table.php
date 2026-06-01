<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('destinations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255)->nullable();
            $table->string('main_bot_token', 255)->nullable();
            $table->string('ai_bot_token', 255)->nullable();
            $table->string('target', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $fonnteApiKey = DB::table('settings')->where('key', 'fonnte_api_key')->value('value');
        $fonnteTarget = DB::table('settings')->where('key', 'fonnte_phone_number')->value('value');
        $telegramMainToken = DB::table('settings')->where('key', 'telegram_bot_token')->value('value');
        $telegramAiToken = DB::table('settings')->where('key', 'telegram_bot_ai_token')->value('value');
        $telegramTarget = DB::table('settings')->where('key', 'telegram_group_id')->value('value');

        DB::table('destinations')->insert([
            [
                'code' => 'whatsapp',
                'name' => 'WhatsApp',
                'main_bot_token' => $fonnteApiKey,
                'ai_bot_token' => null,
                'target' => $fonnteTarget,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'telegram',
                'name' => 'Telegram',
                'main_bot_token' => $telegramMainToken,
                'ai_bot_token' => $telegramAiToken,
                'target' => $telegramTarget,
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
        Schema::dropIfExists('destinations');
    }
};
