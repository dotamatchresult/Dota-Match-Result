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
            'total_kills' => "Dapatkan {$requirement} total kill",
            'total_denies' => "Dapatkan {$requirement} total deny",
            'total_heal' => 'Pulihkan '.number_format($requirement, 0, ',', '.').' HP',
            'last_hits' => "Dapatkan {$requirement} last hit dalam satu pertandingan",
            'zero_death_win' => 'Menangkan pertandingan tanpa mati',
            'fast_win' => "Menangkan pertandingan dalam waktu kurang dari {$requirement} menit",
            // Accumulative Team (new)
            'total_assists' => "Dapatkan {$requirement} total assist",
            'total_hero_damage' => 'Berikan '.number_format($requirement, 0, ',', '.').' total hero damage',
            'total_tower_damage' => 'Berikan '.number_format($requirement, 0, ',', '.').' total tower damage',
            'total_last_hits' => "Dapatkan {$requirement} total last hit",
            'total_net_worth' => 'Kumpulkan '.number_format($requirement, 0, ',', '.').' total net worth',
            // Single Match Team (new)
            'team_assists_match' => "Dapatkan {$requirement} total assist tim dalam satu pertandingan",
            'team_kills_match' => "Dapatkan {$requirement} total kill tim dalam satu pertandingan",
            'team_last_hits_match' => "Dapatkan {$requirement} total last hit tim dalam satu pertandingan",
            'team_denies_match' => "Dapatkan {$requirement} total deny tim dalam satu pertandingan",
            'team_hero_damage_match' => 'Berikan '.number_format($requirement, 0, ',', '.').' total hero damage tim dalam satu pertandingan',
            'team_tower_damage_match' => 'Berikan '.number_format($requirement, 0, ',', '.').' total tower damage tim dalam satu pertandingan',
            // Single Match Individual (new)
            'player_kills_match' => "Seorang pemain mencapai {$requirement} kill dalam satu pertandingan",
            'player_assists_match' => "Seorang pemain mencapai {$requirement} assist dalam satu pertandingan",
            'player_last_hits_match' => "Seorang pemain mencapai {$requirement} last hit dalam satu pertandingan",
            'player_hero_damage_match' => 'Seorang pemain mencapai '.number_format($requirement, 0, ',', '.').' hero damage dalam satu pertandingan',
            'player_tower_damage_match' => 'Seorang pemain mencapai '.number_format($requirement, 0, ',', '.').' tower damage dalam satu pertandingan',
            'player_net_worth_match' => 'Seorang pemain mencapai '.number_format($requirement, 0, ',', '.').' net worth dalam satu pertandingan',
            'player_gpm_match' => "Seorang pemain mencapai {$requirement} GPM dalam satu pertandingan",
            'player_xpm_match' => "Seorang pemain mencapai {$requirement} XPM dalam satu pertandingan",
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

        return "Menangkan {$requirement} pertandingan menggunakan {$heroName}";
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

        return "Menangkan {$requirement} pertandingan dengan membawa {$itemName}";
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
