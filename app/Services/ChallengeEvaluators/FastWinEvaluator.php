<?php

namespace App\Services\ChallengeEvaluators;

use App\Contracts\Challenges\ChallengeEvaluator;
use App\DataObjects\Challenges\EvaluationResult;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;
use Illuminate\Support\Collection;

class FastWinEvaluator implements ChallengeEvaluator
{
    /**
     * Evaluate a FAST_WIN challenge.
     *
     * Qualifying condition: any participating member's team must win the match
     * AND the match duration must be <= the current requirement (in minutes).
     *
     * This is a snapshot challenge — completion is binary.
     */
    public function evaluate(
        DestinationChallenge $challenge,
        DotaMatch $match,
        Collection $participatingMembers
    ): EvaluationResult {
        if ($participatingMembers->isEmpty()) {
            return new EvaluationResult(matched: false);
        }

        $matchData = $match->match_data;
        $radiantWin = $matchData['radiant_win'] ?? false;
        $durationSeconds = (int) ($matchData['duration'] ?? 0);
        $durationMinutes = (int) floor($durationSeconds / 60);

        $requirement = (int) $challenge->current_requirement;

        // Duration must be within the requirement
        if ($durationMinutes > $requirement) {
            return new EvaluationResult(matched: false);
        }

        $players = $matchData['players'] ?? [];

        // Index players by account_id for fast lookup
        $playersByAccountId = [];
        foreach ($players as $player) {
            $accountId = $player['account_id'] ?? null;
            if ($accountId !== null) {
                $playersByAccountId[$accountId] = $player;
            }
        }

        // Check if any participating member won
        $anyWinner = false;
        foreach ($participatingMembers as $member) {
            $accountId = Member::convertSteamIdToAccountId($member->steam_id);
            $player = $playersByAccountId[$accountId] ?? null;

            if ($player === null) {
                continue;
            }

            $playerSlot = $player['player_slot'] ?? 0;
            $isRadiant = $playerSlot < 128;
            $playerWon = $isRadiant === $radiantWin;

            if ($playerWon) {
                $anyWinner = true;
                break;
            }
        }

        if (! $anyWinner) {
            return new EvaluationResult(matched: false);
        }

        // Challenge already completed? (idempotency: current_progress >= 1 means done)
        if (($challenge->current_progress ?? 0) >= 1) {
            return new EvaluationResult(matched: false);
        }

        return new EvaluationResult(
            matched: true,
            progressDelta: 1,
            contributors: [],
            progressData: [
                'contributors' => [],
                'matches' => [(int) $match->match_id],
                'best_attempt' => $durationMinutes,
                'best_match_id' => (int) $match->match_id,
                'metadata' => [
                    'duration_minutes' => $durationMinutes,
                    'duration_seconds' => $durationSeconds,
                ],
            ],
        );
    }
}
