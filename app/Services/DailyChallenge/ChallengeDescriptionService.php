<?php

namespace App\Services\DailyChallenge;

use App\Models\DestinationChallenge;
use App\Models\Hero;
use App\Models\Item;

class ChallengeDescriptionService
{
    /**
     * Generate a human-readable description of the challenge in Bahasa Indonesia.
     *
     * Uses runtime metadata from progress_data when available,
     * falling back to challenge configuration.
     */
    public function describe(DestinationChallenge $destinationChallenge): string
    {
        $challenge = $destinationChallenge->challenge;
        $requirement = $destinationChallenge->current_requirement;
        $metadata = $destinationChallenge->progress_data['metadata'] ?? [];
        $configuration = $challenge->configuration ?? [];

        return match ($challenge->code) {
            'hero_win' => $this->describeHeroWin($requirement, $metadata, $configuration),
            'item_win' => $this->describeItemWin($requirement, $metadata, $configuration),
            'total_kills' => "Dapatkan total {$requirement} kill",
            'total_denies' => "Dapatkan total {$requirement} deny",
            'total_heal' => 'Pulihkan '.number_format($requirement, 0, ',', '.').' HP',
            'last_hits' => "Dapatkan {$requirement} last hit dalam satu match",
            'zero_death_win' => 'Menangkan match, salah satu player harus 0 death',
            'fast_win' => "Menangkan match dalam waktu kurang dari {$requirement} menit",

            // Accumulative Team (new)
            'total_assists' => "Dapatkan total {$requirement} assist",
            'total_hero_damage' => 'Berikan total '.number_format($requirement, 0, ',', '.').' hero damage',
            'total_tower_damage' => 'Berikan total '.number_format($requirement, 0, ',', '.').' tower damage',
            'total_last_hits' => "Dapatkan total {$requirement} last hit",
            'total_net_worth' => 'Kumpulkan total '.number_format($requirement, 0, ',', '.').' net worth',

            // Single Match Team (new)
            'team_assists_match' => "Dapatkan total {$requirement} assist tim dalam satu match",
            'team_kills_match' => "Dapatkan total {$requirement} kill tim dalam satu match",
            'team_last_hits_match' => "Dapatkan total {$requirement} last hit tim dalam satu match",
            'team_denies_match' => "Dapatkan total {$requirement} deny tim dalam satu match",
            'team_hero_damage_match' => 'Berikan total '.number_format($requirement, 0, ',', '.').' hero damage tim dalam satu match',
            'team_tower_damage_match' => 'Berikan total '.number_format($requirement, 0, ',', '.').' tower damage tim dalam satu match',

            // Single Match Individual (new)
            'player_kills_match' => "Satu player mencapai {$requirement} kill dalam satu match",
            'player_assists_match' => "Satu player mencapai {$requirement} assist dalam satu match",
            'player_last_hits_match' => "Satu player mencapai {$requirement} last hit dalam satu match",
            'player_hero_damage_match' => 'Satu player mencapai '.number_format($requirement, 0, ',', '.').' hero damage dalam satu match',
            'player_tower_damage_match' => 'Satu player mencapai '.number_format($requirement, 0, ',', '.').' tower damage dalam satu match',
            'player_net_worth_match' => 'Satu player mencapai '.number_format($requirement, 0, ',', '.').' net worth dalam satu match',
            'player_gpm_match' => "Satu player mencapai {$requirement} GPM dalam satu match",
            'player_xpm_match' => "Satu player mencapai {$requirement} XPM dalam satu match",
            default => $this->describeFallback($challenge->description, $requirement),
        };
    }

    /**
     * Describe a hero_win challenge.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $configuration
     */
    private function describeHeroWin(int $requirement, array $metadata, array $configuration): string
    {
        $heroName = $this->resolveHeroName($metadata, $configuration);

        return "Menangkan {$requirement} match menggunakan {$heroName}";
    }

    /**
     * Describe an item_win challenge.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $configuration
     */
    private function describeItemWin(int $requirement, array $metadata, array $configuration): string
    {
        $itemName = $this->resolveItemName($metadata, $configuration);

        return "Menangkan {$requirement} match dengan membawa {$itemName} hingga akhir";
    }

    /**
     * Fallback description using the challenge's description field.
     */
    private function describeFallback(string $description, int $requirement): string
    {
        return str_replace('{requirement}', (string) $requirement, $description);
    }

    /**
     * Resolve the hero name from metadata, configuration, or fallback.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $configuration
     */
    private function resolveHeroName(array $metadata, array $configuration): string
    {
        $heroId = $metadata['hero_id'] ?? $configuration['hero_id'] ?? null;

        if ($heroId !== null) {
            $hero = Hero::query()->where('hero_id', $heroId)->first();

            if ($hero) {
                return $hero->localized_name ?? $hero->name;
            }
        }

        return 'Hero yang ditentukan';
    }

    /**
     * Resolve the item name from metadata, configuration, or fallback.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $configuration
     */
    private function resolveItemName(array $metadata, array $configuration): string
    {
        $itemId = $metadata['item_id'] ?? $configuration['item_id'] ?? null;

        if ($itemId !== null) {
            $item = Item::query()->where('item_id', $itemId)->first();

            if ($item) {
                return $item->dname ?? $item->name;
            }
        }

        return 'Item yang ditentukan';
    }
}
