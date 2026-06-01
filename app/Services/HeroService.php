<?php

namespace App\Services;

use App\Models\Hero;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HeroService
{
    /**
     * Get hero name by hero ID
     */
    public function getHeroName(int $heroId): string
    {
        $hero = Hero::where('hero_id', $heroId)->first();

        if ($hero) {
            return $hero->localized_name;
        }

        Log::warning('Unknown hero ID', ['hero_id' => $heroId]);

        return "Hero #{$heroId}";
    }

    /**
     * Sync heroes from OpenDota API
     */
    public function syncHeroes(): bool
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(60)->get('https://api.opendota.com/api/heroes');

            if (! $response->successful()) {
                Log::error('Failed to fetch heroes from OpenDota API', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            $heroes = $response->json();

            if (empty($heroes)) {
                Log::error('No heroes returned from OpenDota API');

                return false;
            }

            foreach ($heroes as $hero) {
                Hero::updateOrCreate(
                    ['hero_id' => $hero['id']],
                    [
                        'name' => $hero['name'],
                        'localized_name' => $hero['localized_name'],
                    ]
                );
            }

            Log::info('Heroes synced successfully', ['count' => count($heroes)]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to sync heroes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
