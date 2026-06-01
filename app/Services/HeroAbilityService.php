<?php

namespace App\Services;

use App\Models\HeroAbility;
use App\Models\HeroFacet;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HeroAbilityService
{
    /**
     * Get all ability names for a given hero NPC name
     *
     * @return array<int, string>
     */
    public function getAbilitiesForHero(string $heroNpcName): array
    {
        return HeroAbility::where('hero_npc_name', $heroNpcName)
            ->pluck('name')
            ->all();
    }

    /**
     * Get all facets for a given hero NPC name
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, HeroFacet>
     */
    public function getFacetsForHero(string $heroNpcName)
    {
        return HeroFacet::where('hero_npc_name', $heroNpcName)->get();
    }

    /**
     * Sync hero abilities (skills) and facets from OpenDota API
     */
    public function sync(): bool
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(60)->get('https://api.opendota.com/api/constants/hero_abilities');

            if (! $response->successful()) {
                Log::error('Failed to fetch hero abilities from OpenDota API', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            $heroes = $response->json();

            if (empty($heroes)) {
                Log::error('No hero ability data returned from OpenDota API');

                return false;
            }

            $abilityCount = 0;
            $facetCount = 0;

            foreach ($heroes as $heroNpcName => $data) {
                foreach ($data['abilities'] ?? [] as $abilityName) {
                    HeroAbility::updateOrCreate(
                        ['name' => $abilityName],
                        ['hero_npc_name' => $heroNpcName]
                    );
                    $abilityCount++;
                }

                foreach ($data['facets'] ?? [] as $facet) {
                    HeroFacet::updateOrCreate(
                        ['name' => $facet['name']],
                        [
                            'hero_npc_name' => $heroNpcName,
                            'title' => $facet['title'] ?? $facet['name'],
                            'icon' => $facet['icon'] ?? null,
                            'color' => $facet['color'] ?? null,
                        ]
                    );
                    $facetCount++;
                }
            }

            Log::info('Hero abilities and facets synced successfully', [
                'heroes' => count($heroes),
                'abilities' => $abilityCount,
                'facets' => $facetCount,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to sync hero abilities', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
