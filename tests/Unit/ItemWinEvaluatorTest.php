<?php

use App\DataObjects\Challenges\EvaluationResult;
use App\Models\Challenge;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Services\ChallengeEvaluators\ItemWinEvaluator;

beforeEach(function () {
    $this->evaluator = new ItemWinEvaluator;
});

/**
 * Create a Member instance without persisting to the database.
 */
function iewMember(int $id, string $steamId): Member
{
    $member = new Member(['steam_id' => $steamId, 'name' => "Player{$id}"]);
    $member->id = $id;

    return $member;
}

/**
 * Create a Challenge instance without persisting to the database.
 */
function iewChallenge(string $code, ?array $configuration = null): Challenge
{
    $challenge = new Challenge(['code' => $code, 'configuration' => $configuration]);
    $challenge->id = 1;

    return $challenge;
}

/**
 * Create a DestinationChallenge with a related Challenge, without persisting.
 */
function iewDestinationChallenge(Challenge $challenge, ?array $progressData = null): DestinationChallenge
{
    $dc = new DestinationChallenge([
        'current_requirement' => 2,
        'progress_data' => $progressData,
    ]);
    $dc->setRelation('challenge', $challenge);

    return $dc;
}

/**
 * Create a DotaMatch with specific match_data and member IDs, without persisting.
 */
function iewMatch(string $matchId, array $matchData, array $memberIds): DotaMatch
{
    $match = new DotaMatch([
        'match_id' => $matchId,
        'match_data' => $matchData,
        'members' => $memberIds,
    ]);
    $match->id = (int) $matchId;

    return $match;
}

// --- Qualifying Member ---

test('qualifying member with matching item contributes', function () {
    $itemId = 116; // Black King Bar

    $member = iewMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = iewMatch('8100000001', [
        'radiant_win' => true,
        'players' => [
            [
                'account_id' => $accountId,
                'player_slot' => 0, // Radiant
                'hero_id' => 14,
                'item_0' => 48,
                'item_1' => $itemId,
                'item_2' => 0,
                'item_3' => 0,
                'item_4' => 0,
                'item_5' => 0,
                'backpack_0' => 0,
                'backpack_1' => 0,
                'backpack_2' => 0,
                'kills' => 5,
                'deaths' => 2,
                'assists' => 10,
            ],
        ],
    ], [$member->id]);

    $challenge = iewChallenge('item_win', ['item_id' => $itemId]);
    $destinationChallenge = iewDestinationChallenge($challenge);

    $result = $this->evaluator->evaluate(
        $destinationChallenge,
        $match,
        collect([$member])
    );

    expect($result)->toBeInstanceOf(EvaluationResult::class);
    expect($result->matched)->toBeTrue();
    expect($result->progressDelta)->toBe(1);
    expect($result->contributors)->toHaveCount(1);
    expect($result->contributors[0]['member_id'])->toBe($member->id);
    expect($result->contributors[0]['value'])->toBe(1);
});

// --- Wrong Item ---

test('wrong item is ignored', function () {
    $configuredItemId = 116; // BKB
    $heldItemId = 48;        // Power Treads

    $member = iewMember(2, '76561197960265730');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = iewMatch('8100000002', [
        'radiant_win' => true,
        'players' => [
            [
                'account_id' => $accountId,
                'player_slot' => 0,
                'hero_id' => 14,
                'item_0' => $heldItemId,
                'item_1' => 0,
                'item_2' => 0,
                'item_3' => 0,
                'item_4' => 0,
                'item_5' => 0,
                'backpack_0' => 0,
                'backpack_1' => 0,
                'backpack_2' => 0,
                'kills' => 3,
                'deaths' => 1,
                'assists' => 5,
            ],
        ],
    ], [$member->id]);

    $challenge = iewChallenge('item_win', ['item_id' => $configuredItemId]);
    $destinationChallenge = iewDestinationChallenge($challenge);

    $result = $this->evaluator->evaluate(
        $destinationChallenge,
        $match,
        collect([$member])
    );

    expect($result->matched)->toBeFalse();
    expect($result->progressDelta)->toBe(0);
});

// --- Losing Match ---

test('losing match is ignored even with matching item', function () {
    $itemId = 116; // BKB

    $member = iewMember(3, '76561197960265731');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = iewMatch('8100000003', [
        'radiant_win' => false, // Dire won
        'players' => [
            [
                'account_id' => $accountId,
                'player_slot' => 0, // Radiant — lost
                'hero_id' => 14,
                'item_0' => $itemId,
                'item_1' => 0,
                'item_2' => 0,
                'item_3' => 0,
                'item_4' => 0,
                'item_5' => 0,
                'backpack_0' => 0,
                'backpack_1' => 0,
                'backpack_2' => 0,
                'kills' => 3,
                'deaths' => 5,
                'assists' => 8,
            ],
        ],
    ], [$member->id]);

    $challenge = iewChallenge('item_win', ['item_id' => $itemId]);
    $destinationChallenge = iewDestinationChallenge($challenge);

    $result = $this->evaluator->evaluate(
        $destinationChallenge,
        $match,
        collect([$member])
    );

    expect($result->matched)->toBeFalse();
    expect($result->progressDelta)->toBe(0);
});

// --- Two Qualifying Members ---

test('two qualifying members contribute progress delta of two', function () {
    $itemId = 116; // BKB

    $alice = iewMember(4, '76561197960265732');
    $bob = iewMember(5, '76561197960265733');
    $aliceAccountId = Member::convertSteamIdToAccountId($alice->steam_id);
    $bobAccountId = Member::convertSteamIdToAccountId($bob->steam_id);

    $match = iewMatch('8100000004', [
        'radiant_win' => true,
        'players' => [
            [
                'account_id' => $aliceAccountId,
                'player_slot' => 0,
                'hero_id' => 14,
                'item_0' => $itemId,
                'item_1' => 50,
                'item_2' => 0,
                'item_3' => 0,
                'item_4' => 0,
                'item_5' => 0,
                'backpack_0' => 0,
                'backpack_1' => 0,
                'backpack_2' => 0,
                'kills' => 5,
                'deaths' => 1,
                'assists' => 9,
            ],
            [
                'account_id' => $bobAccountId,
                'player_slot' => 1,
                'hero_id' => 22,
                'item_0' => 0,
                'item_1' => 0,
                'item_2' => 0,
                'item_3' => $itemId,
                'item_4' => 0,
                'item_5' => 0,
                'backpack_0' => 0,
                'backpack_1' => 0,
                'backpack_2' => 0,
                'kills' => 3,
                'deaths' => 2,
                'assists' => 12,
            ],
        ],
    ], [$alice->id, $bob->id]);

    $challenge = iewChallenge('item_win', ['item_id' => $itemId]);
    $destinationChallenge = iewDestinationChallenge($challenge);

    $result = $this->evaluator->evaluate(
        $destinationChallenge,
        $match,
        collect([$alice, $bob])
    );

    expect($result->matched)->toBeTrue();
    expect($result->progressDelta)->toBe(2);
    expect($result->contributors)->toHaveCount(2);
});

// --- Item in Backpack ---

test('item in backpack qualifies', function () {
    $itemId = 116; // BKB

    $member = iewMember(6, '76561197960265734');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = iewMatch('8100000005', [
        'radiant_win' => true,
        'players' => [
            [
                'account_id' => $accountId,
                'player_slot' => 0,
                'hero_id' => 14,
                'item_0' => 48,
                'item_1' => 0,
                'item_2' => 0,
                'item_3' => 0,
                'item_4' => 0,
                'item_5' => 0,
                'backpack_0' => $itemId,
                'backpack_1' => 0,
                'backpack_2' => 0,
                'kills' => 5,
                'deaths' => 2,
                'assists' => 10,
            ],
        ],
    ], [$member->id]);

    $challenge = iewChallenge('item_win', ['item_id' => $itemId]);
    $destinationChallenge = iewDestinationChallenge($challenge);

    $result = $this->evaluator->evaluate(
        $destinationChallenge,
        $match,
        collect([$member])
    );

    expect($result->matched)->toBeTrue();
    expect($result->progressDelta)->toBe(1);
});

// --- No Members ---

test('no destination members results in no match', function () {
    $itemId = 116;

    $challenge = iewChallenge('item_win', ['item_id' => $itemId]);
    $destinationChallenge = iewDestinationChallenge($challenge);

    $match = iewMatch('8100000006', [
        'radiant_win' => true,
        'players' => [],
    ], []);

    $result = $this->evaluator->evaluate(
        $destinationChallenge,
        $match,
        collect([])
    );

    expect($result->matched)->toBeFalse();
    expect($result->progressDelta)->toBe(0);
});

// --- Resolves item_id from progress_data.metadata ---

test('resolves item_id from progress_data metadata', function () {
    $itemId = 208; // Refresher Orb

    $member = iewMember(7, '76561197960265735');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = iewMatch('8100000007', [
        'radiant_win' => true,
        'players' => [
            [
                'account_id' => $accountId,
                'player_slot' => 0,
                'hero_id' => 14,
                'item_0' => $itemId,
                'item_1' => 0,
                'item_2' => 0,
                'item_3' => 0,
                'item_4' => 0,
                'item_5' => 0,
                'backpack_0' => 0,
                'backpack_1' => 0,
                'backpack_2' => 0,
                'kills' => 5,
                'deaths' => 2,
                'assists' => 10,
            ],
        ],
    ], [$member->id]);

    // Challenge template has no item_id — it comes from progress_data (assignment time)
    $challenge = iewChallenge('item_win', null);
    $destinationChallenge = iewDestinationChallenge($challenge, [
        'metadata' => ['item_id' => $itemId],
    ]);

    $result = $this->evaluator->evaluate(
        $destinationChallenge,
        $match,
        collect([$member])
    );

    expect($result->matched)->toBeTrue();
    expect($result->progressDelta)->toBe(1);
});
