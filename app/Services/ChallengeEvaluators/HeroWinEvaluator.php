<?php

namespace App\Services\ChallengeEvaluators;

use App\Contracts\Challenges\ChallengeEvaluator;
use App\DataObjects\Challenges\EvaluationResult;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;
use Illuminate\Support\Collection;

class HeroWinEvaluator implements ChallengeEvaluator
{
    /**
     * Evaluate a HERO_WIN challenge.
     *
     * Qualifying condition: member must use the configured hero AND win the match.
     * Each qualifying member contributes +1 to progress.
     */
    public function evaluate(
        DestinationChallenge $challenge,
        DotaMatch $match,
        Collection $participatingMembers
    ): EvaluationResult {
        $heroId = $this->resolveHeroId($challenge);

        if ($heroId === null) {
            return new EvaluationResult(matched: false);
        }

        if ($participatingMembers->isEmpty()) {
            return new EvaluationResult(matched: false);
        }

        $matchData = $match->match_data;
        $radiantWin = $matchData['radiant_win'] ?? false;
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
        $qualifyingCount = 0;

        foreach ($participatingMembers as $member) {
            $accountId = Member::convertSteamIdToAccountId($member->steam_id);
            $player = $playersByAccountId[$accountId] ?? null;

            if ($player === null) {
                continue;
            }

            // Check hero matches
            $playerHeroId = $player['hero_id'] ?? null;
            if ((int) $playerHeroId !== (int) $heroId) {
                continue;
            }

            // Check win: player's team must have won
            $playerSlot = $player['player_slot'] ?? 0;
            $isRadiant = $playerSlot < 128;
            $playerWon = $isRadiant === $radiantWin;

            if (! $playerWon) {
                continue;
            }

            $qualifyingCount++;
            $contributors[] = [
                'member_id' => $member->id,
                'value' => 1,
            ];
        }

        if ($qualifyingCount === 0) {
            return new EvaluationResult(matched: false);
        }

        return new EvaluationResult(
            matched: true,
            progressDelta: $qualifyingCount,
            contributors: $contributors,
            progressData: [
                'contributors' => $this->buildContributorsMap($contributors),
                'matches' => [(int) $match->match_id],
                'metadata' => ['hero_id' => (int) $heroId],
            ],
        );
    }

    /**
     * Resolve the hero_id from the destination challenge or its parent template.
     */
    private function resolveHeroId(DestinationChallenge $challenge): ?int
    {
        // Primary: set at assignment time in progress_data.metadata
        $progressData = $challenge->progress_data;
        if (isset($progressData['metadata']['hero_id'])) {
            return (int) $progressData['metadata']['hero_id'];
        }

        // Fallback: set on the challenge template configuration
        $configuration = $challenge->challenge->configuration;
        if (isset($configuration['hero_id'])) {
            return (int) $configuration['hero_id'];
        }

        return null;
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
