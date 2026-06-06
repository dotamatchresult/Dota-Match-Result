<?php

namespace App\Services\ChallengeEvaluators;

use App\Contracts\Challenges\ChallengeEvaluator;
use App\DataObjects\Challenges\EvaluationResult;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;
use Illuminate\Support\Collection;

class SingleMatchIndividualMetricEvaluator implements ChallengeEvaluator
{
    /**
     * Evaluate a single-match individual-metric challenge.
     *
     * Finds the highest metric value among participating members within
     * a single match. Tracks best_attempt, best_member_id, and best_match_id.
     *
     * Configuration: ['metric' => 'last_hits']
     */
    public function evaluate(
        DestinationChallenge $challenge,
        DotaMatch $match,
        Collection $participatingMembers
    ): EvaluationResult {
        $configuration = $challenge->challenge->configuration;
        $metric = $configuration['metric'] ?? null;

        if ($metric === null || $participatingMembers->isEmpty()) {
            return new EvaluationResult(matched: false);
        }

        $matchData = $match->match_data;
        $players = $matchData['players'] ?? [];

        // Index players by account_id for fast lookup
        $playersByAccountId = [];
        foreach ($players as $player) {
            $accountId = $player['account_id'] ?? null;
            if ($accountId !== null) {
                $playersByAccountId[$accountId] = $player;
            }
        }

        $bestMemberId = null;
        $bestValue = 0;

        foreach ($participatingMembers as $member) {
            $accountId = Member::convertSteamIdToAccountId($member->steam_id);
            $player = $playersByAccountId[$accountId] ?? null;

            if ($player === null) {
                continue;
            }

            $value = (int) ($player[$metric] ?? 0);

            if ($value > $bestValue) {
                $bestValue = $value;
                $bestMemberId = $member->id;
            }
        }

        if ($bestValue === 0) {
            return new EvaluationResult(matched: false);
        }

        $previousBest = $challenge->progress_data['best_attempt'] ?? null;
        $previousBest = $previousBest !== null ? (int) $previousBest : 0;

        // Only report progress if this match beats the previous best
        if ($bestValue <= $previousBest) {
            return new EvaluationResult(matched: false);
        }

        $progressDelta = $bestValue - $previousBest;

        $contributors = [];
        if ($bestMemberId !== null) {
            $contributors[] = [
                'member_id' => $bestMemberId,
                'value' => $bestValue,
            ];
        }

        return new EvaluationResult(
            matched: true,
            progressDelta: $progressDelta,
            contributors: $contributors,
            progressData: [
                'contributors' => $this->buildContributorsMap($contributors),
                'matches' => [(int) $match->match_id],
                'best_attempt' => $bestValue,
                'best_member_id' => $bestMemberId,
                'best_match_id' => (int) $match->match_id,
                'metadata' => ['metric' => $metric],
            ],
        );
    }

    /**
     * Build the contributors map from flat contributor entries.
     *
     * @param  array<int, array{member_id: int, value: int}>  $contributors
     * @return array<int, int>
     */
    private function buildContributorsMap(array $contributors): array
    {
        $map = [];
        foreach ($contributors as $contributor) {
            $memberId = $contributor['member_id'];
            $map[$memberId] = ($map[$memberId] ?? 0) + $contributor['value'];
        }

        return $map;
    }
}
