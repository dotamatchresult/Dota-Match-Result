<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenDotaParseService
{
    private string $baseUrl = 'https://api.opendota.com';

    /**
     * Request OpenDota to parse a match
     */
    public function requestParse(string $matchId): ?array
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(60)
                ->post("{$this->baseUrl}/api/request/{$matchId}");

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('OpenDota API requestParse failed', [
                'match_id' => $matchId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('OpenDota API requestParse exception', [
                'match_id' => $matchId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check parse job status
     * Returns null when parsing is complete
     */
    public function checkParseStatus(string $jobId): mixed
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(60)
                ->get("{$this->baseUrl}/api/request/{$jobId}");

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('OpenDota API checkParseStatus failed', [
                'job_id' => $jobId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('OpenDota API checkParseStatus exception', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
