<?php

namespace Database\Seeders;

use App\Enums\DestinationType;
use App\Models\Member;
use Illuminate\Database\Seeder;

class MemberSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $destinationWA = DestinationType::WhatsApp->value;
        $destinationTele = DestinationType::Telegram->value;

        $members = [
            // WhatsApp Members
            [
                'steam_id' => '76561198256821667',
                'name' => 'Aldi',
                'destination' => $destinationWA,
            ],

            // Telegram Members
            [
                'steam_id' => '76561198256821667',
                'name' => 'Aldisaster',
                'destination' => $destinationTele,
            ],
        ];

        foreach ($members as $member) {
            Member::firstOrCreate(
                ['steam_id' => $member['steam_id'], 'destination' => $member['destination']],
                ['name' => $member['name']]
            );
        }
    }
}
