<?php

namespace App\Jobs;

use App\Models\DotaMatch;
use App\Services\OpenDotaParseService;
use App\Services\SteamApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CheckParseStatus implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public DotaMatch $dotaMatch
    ) {}

    /**
     * Execute the job.
     */
    public function handle(OpenDotaParseService $parseService, SteamApiService $steamApi): void
    {
        if (! $this->dotaMatch->parse_job_id) {
            Log::warning('Match has no parse job ID', [
                'match_id' => $this->dotaMatch->match_id,
            ]);

            return;
        }

        // Check parse status
        $status = $parseService->checkParseStatus($this->dotaMatch->parse_job_id);

        // If status is null, parsing is complete
        if ($status === null) {
            Log::info('Parse completed for match', [
                'match_id' => $this->dotaMatch->match_id,
            ]);

            // Re-fetch match details with parsed data
            $matchDetails = $steamApi->getMatchDetails($this->dotaMatch->match_id);

            if (! $matchDetails) {
                Log::error('Failed to fetch parsed match details', [
                    'match_id' => $this->dotaMatch->match_id,
                ]);

                // Dispatch retry job
                RetryMatchParse::dispatch($this->dotaMatch);

                return;
            }

            // Update match data with parsed details — include all fields needed by the AI analysis pipeline
            $relevantData = [
                'match_id' => $matchDetails['match_id'] ?? null,
                'radiant_win' => $matchDetails['radiant_win'] ?? false,
                'game_mode' => $matchDetails['game_mode'] ?? null,
                'duration' => $matchDetails['duration'] ?? null,
                'radiant_score' => $matchDetails['radiant_score'] ?? 0,
                'dire_score' => $matchDetails['dire_score'] ?? 0,
                'first_blood_time' => $matchDetails['first_blood_time'] ?? null,
                // Required by NormalizerAgent for gold/xp advantage curves
                'radiant_gold_adv' => $matchDetails['radiant_gold_adv'] ?? [],
                'radiant_xp_adv' => $matchDetails['radiant_xp_adv'] ?? [],
                // Required by TeamfightAggregatorAgent
                'teamfights' => $matchDetails['teamfights'] ?? [],
                // Required by ObjectiveFlowAgent
                'objectives' => $matchDetails['objectives'] ?? [],
                'players' => array_map(function ($player) {
                    return [
                        'account_id' => $player['account_id'] ?? null,
                        'player_slot' => $player['player_slot'] ?? 0,
                        'hero_id' => $player['hero_id'] ?? 0,
                        'kills' => $player['kills'] ?? 0,
                        'deaths' => $player['deaths'] ?? 0,
                        'assists' => $player['assists'] ?? 0,
                        'hero_damage' => $player['hero_damage'] ?? 0,
                        'tower_damage' => $player['tower_damage'] ?? 0,
                        'hero_healing' => $player['hero_healing'] ?? 0,
                        'last_hits' => $player['last_hits'] ?? 0,
                        'level' => $player['level'] ?? 0,
                        'net_worth' => $player['net_worth'] ?? 0,
                        'gold_per_min' => $player['gold_per_min'] ?? 0,
                        'xp_per_min' => $player['xp_per_min'] ?? 0,
                        // Required by NormalizerAgent for objective participation
                        'roshan_kills' => $player['roshan_kills'] ?? 0,
                        'runes' => $player['runes'] ?? [],
                    ];
                }, $matchDetails['players'] ?? []),
            ];

            $this->dotaMatch->update([
                'match_data' => $relevantData,
                'parse_status' => 'parsed',
                'parse_completed_at' => now(),
            ]);

            // Dispatch AI analysis job
            AnalyzeMatchWithAI::dispatch($this->dotaMatch);
        } else {
            // Still parsing, dispatch retry job
            Log::info('Match still parsing', [
                'match_id' => $this->dotaMatch->match_id,
                'job_id' => $this->dotaMatch->parse_job_id,
            ]);

            RetryMatchParse::dispatch($this->dotaMatch);
        }
    }

    /**
     * Get the number of times the job should be attempted.
     */
    public function tries(): int
    {
        return 3;
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [30, 120, 300]; // 30s, 2min, 5min
    }
}
