<?php

namespace App\Services\Analysis\Agents;

use App\DataObjects\Analysis\GatekeeperResult;
use Illuminate\Support\Facades\Log;

class MatchGatekeeperAgent
{
    private const MIN_DURATION = 600; // 10 minutes

    public function validate(array $matchData, array $memberSteamIds): GatekeeperResult
    {
        // Check duration - avoid remakes
        if (($matchData['duration'] ?? 0) < self::MIN_DURATION) {
            return GatekeeperResult::reject(
                'Match too short (likely remake)',
                'duration_too_short'
            );
        }

        // Check teamfights exist
        if (empty($matchData['teamfights'])) {
            Log::warning('Match has no teamfights data', [
                'match_id' => $matchData['match_id'] ?? 'unknown',
            ]);

            return GatekeeperResult::reject(
                'No teamfights data available',
                'missing_teamfights'
            );
        }

        // Check radiant_win is set
        if (! isset($matchData['radiant_win'])) {
            return GatekeeperResult::reject(
                'Match outcome unknown',
                'outcome_unknown'
            );
        }

        // Verify members played and lost
        $memberTeam = $this->determineMemberTeam($matchData, $memberSteamIds);
        if (! $memberTeam) {
            return GatekeeperResult::reject(
                'Could not determine member team',
                'team_unknown'
            );
        }

        $memberWon = ($memberTeam === 'radiant' && $matchData['radiant_win'])
            || ($memberTeam === 'dire' && ! $matchData['radiant_win']);

        if ($memberWon) {
            return GatekeeperResult::reject(
                'Members won this match',
                'victory_not_defeat'
            );
        }

        Log::info('Match passed gatekeeper validation', [
            'match_id' => $matchData['match_id'] ?? 'unknown',
        ]);

        return GatekeeperResult::pass();
    }

    private function determineMemberTeam(array $matchData, array $memberSteamIds): ?string
    {
        foreach ($matchData['players'] ?? [] as $player) {
            $accountId = $player['account_id'] ?? null;
            if (! $accountId) {
                continue;
            }

            $steamId = \App\Models\Member::convertAccountIdToSteamId($accountId);
            if (in_array($steamId, $memberSteamIds)) {
                return ($player['player_slot'] ?? 0) < 128 ? 'radiant' : 'dire';
            }
        }

        return null;
    }
}
