<?php

namespace Database\Seeders;

use App\Models\Destination;
use Illuminate\Database\Seeder;

class DestinationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Destination::query()->updateOrCreate(
            ['code' => Destination::CODE_WHATSAPP],
            [
                'name' => 'WhatsApp',
                'main_bot_token' => 'your_whatsapp_bot_token_here',
                'target' => 'your_whatsapp_target_here',
            ]
        );

        Destination::query()->updateOrCreate(
            ['code' => Destination::CODE_TELEGRAM],
            [
                'name' => 'Telegram',
                'main_bot_token' => 'your_telegram_bot_token_here',
                'ai_bot_token' => 'your_telegram_ai_bot_token_here',
                'target' => 'your_telegram_target_here',
            ]
        );
    }
}
