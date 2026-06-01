<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SteamApiService
{
    private string $apiKey;

    private string $baseUrl = 'https://api.steampowered.com';

    public function __construct()
    {
        $this->apiKey = config('services.steam.api_key');
    }

    /**
     * Get match history for a player
     */
    public function getMatchHistory(string $steamId, int $limit = 10): ?array
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(60)
                ->get("{$this->baseUrl}/IDOTA2Match_570/GetMatchHistory/v1/", [
                    'key' => $this->apiKey,
                    'account_id' => Member::convertSteamIdToAccountId($steamId),
                    'matches_requested' => $limit,
                ]);

            if ($response->successful()) {
                return $response->json('result.matches');
            }

            Log::error('Steam API GetMatchHistory failed', [
                'steam_id' => $steamId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Steam API GetMatchHistory exception', [
                'steam_id' => $steamId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get detailed match information
     */
    public function getMatchDetails(string $matchId): ?array
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(60)
                ->get("https://api.opendota.com/api/matches/{$matchId}");

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('OpenDota API GetMatchDetails failed', [
                'match_id' => $matchId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('OpenDota API GetMatchDetails exception', [
                'match_id' => $matchId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get latest DotA 2 news from Steam
     */
    public function getLatestNews(int $count = 10): ?array
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(60)
                ->get("{$this->baseUrl}/ISteamNews/GetNewsForApp/v0002/", [
                    'appid' => 570,
                    'count' => $count,
                    'format' => 'json',
                ]);

            if ($response->successful()) {
                $newsItems = $response->json('appnews.newsitems', []);

                // Filter to only official DotA 2 announcements
                return collect($newsItems)
                    ->filter(fn ($item) => ($item['feedname'] ?? '') === 'steam_community_announcements')
                    ->values()
                    ->toArray();
            }

            Log::error('Steam API GetLatestNews failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Steam API GetLatestNews exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
