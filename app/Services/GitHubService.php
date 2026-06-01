<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubService
{
    private string $baseUrl = 'https://api.github.com';

    /**
     * Get commits from SteamDatabase/GameTracking-Dota2 since the given datetime.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function getRecentCommits(\DateTimeInterface $since): ?array
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'dota-match-result-tracker',
                ])
                ->get("{$this->baseUrl}/repos/SteamDatabase/GameTracking-Dota2/commits", [
                    'since' => $since->format(\DateTimeInterface::ATOM),
                    'per_page' => 100,
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('GitHub API getRecentCommits failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('GitHub API getRecentCommits exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract the changed file count from a GitHub commit message.
     *
     * Supports the SteamDB format: "{n} | {count} files | ..."
     */
    public function extractFileCount(string $message): int
    {
        if (preg_match('/(\d+)\s+files?/i', $message, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }
}
