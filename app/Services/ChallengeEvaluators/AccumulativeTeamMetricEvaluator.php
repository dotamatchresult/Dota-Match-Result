<?php

namespace App\Services\ChallengeEvaluators;

use App\Contracts\Challenges\ChallengeEvaluator;
use App\DataObjects\Challenges\EvaluationResult;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;
use Illuminate\Support\Collection;

class AccumulativeTeamMetricEvaluator implements ChallengeEvaluator
{
    /**
     * Evaluate an accumulative team-metric challenge.
     *
     * Sums the configured metric across all participating members in the match.
     * Progress accumulates across multiple matches via current_progress.
     *
     * Configuration: ['metric' => 'kills']
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

        $contributors = [];
        $teamTotal = 0;

        foreach ($participatingMembers as $member) {
            $accountId = Member::convertSteamIdToAccountId($member->steam_id);
            $player = $playersByAccountId[$accountId] ?? null;

            if ($player === null) {
                continue;
            }

            $value = (int) ($player[$metric] ?? 0);
            $teamTotal += $value;

            $contributors[] = [
                'member_id' => $member->id,
                'value' => $value,
            ];
        }

        if ($teamTotal === 0) {
            return new EvaluationResult(matched: false);
        }

        return new EvaluationResult(
            matched: true,
            progressDelta: $teamTotal,
            contributors: $contributors,
            progressData: [
                'contributors' => $this->buildContributorsMap($contributors),
                'matches' => [(int) $match->match_id],
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
