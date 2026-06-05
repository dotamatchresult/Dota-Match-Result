<?php

namespace App\Services\ChallengeEvaluators;

use App\Contracts\Challenges\ChallengeEvaluator;
use App\DataObjects\Challenges\EvaluationResult;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;
use Illuminate\Support\Collection;

class ItemWinEvaluator implements ChallengeEvaluator
{
    /** @var array<int, string> Player inventory slot keys to scan for the configured item */
    private const INVENTORY_SLOTS = [
        'item_0', 'item_1', 'item_2', 'item_3', 'item_4', 'item_5',
        'backpack_0', 'backpack_1', 'backpack_2',
    ];

    /**
     * Evaluate an ITEM_WIN challenge.
     *
     * Qualifying condition: member must have the configured item in their inventory
     * AND win the match. Each qualifying member contributes +1 to progress.
     *
     * Item ID resolution order:
     *  1. DestinationChallenge.progress_data.metadata.item_id (set at assignment time)
     *  2. Challenge.configuration.item_id (fallback for manual/test scenarios)
     */
    public function evaluate(
        DestinationChallenge $challenge,
        DotaMatch $match,
        Collection $participatingMembers
    ): EvaluationResult {
        $itemId = $this->resolveItemId($challenge);

        if ($itemId === null) {
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

            // Check if player has the configured item in any inventory slot
            if (! $this->playerHasItem($player, $itemId)) {
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
                'metadata' => ['item_id' => $itemId],
            ],
        );
    }

    /**
     * Resolve the item_id from the destination challenge or its parent template.
     */
    private function resolveItemId(DestinationChallenge $challenge): ?int
    {
        // Primary: set at assignment time in progress_data.metadata
        $progressData = $challenge->progress_data;
        if (isset($progressData['metadata']['item_id'])) {
            return (int) $progressData['metadata']['item_id'];
        }

        // Fallback: set on the challenge template configuration
        $configuration = $challenge->challenge->configuration;
        if (isset($configuration['item_id'])) {
            return (int) $configuration['item_id'];
        }

        return null;
    }

    /**
     * Check whether the player has the given item in any inventory or backpack slot.
     */
    private function playerHasItem(array $player, int $itemId): bool
    {
        foreach (self::INVENTORY_SLOTS as $slot) {
            if (isset($player[$slot]) && (int) $player[$slot] === $itemId) {
                return true;
            }
        }

        return false;
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
