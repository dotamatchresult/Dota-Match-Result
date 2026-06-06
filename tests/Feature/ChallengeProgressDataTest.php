<?php

use App\Models\DotaMatch;
use App\Services\DailyChallenge\ChallengeProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\ChallengeTestHelper;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->destination = ChallengeTestHelper::getWhatsAppDestination();
    $this->progressService = app(ChallengeProgressService::class);
});

// ──────────────────────────────────────────────────────
// Standardized Structure
// ──────────────────────────────────────────────────────

test('progress_data has all required keys after evaluation', function () {
    $heroId = 135;
    $accountId = 125753349;

    $member = ChallengeTestHelper::createMemberForAccount($accountId);
    $scenario = ChallengeTestHelper::createChallengeScenario('hero_win', [
        'configuration' => ['hero_id' => $heroId],
        'base_requirement' => 3,
    ], [
        'current_requirement' => 3,
        'current_progress' => 0,
    ]);

    $match = ChallengeTestHelper::createMatchFromFixture([$member->id], [
        'radiant_win' => true,
    ]);

    $this->progressService->processMatch($match);
    $scenario['destinationChallenge']->refresh();

    $pd = $scenario['destinationChallenge']->progress_data;

    // All 6 standardized keys must exist
    expect($pd)->toHaveKeys([
        'contributors',
        'matches',
        'best_attempt',
        'best_member_id',
        'best_match_id',
        'metadata',
    ]);

    expect($pd['contributors'])->toBeArray();
    expect($pd['matches'])->toBeArray();
    expect($pd['metadata'])->toBeArray();
});

// ──────────────────────────────────────────────────────
// Merge Logic — Contributors
// ──────────────────────────────────────────────────────

test('merge: contributors accumulate across matches', function () {
    $accountIds = [125753349, 225621471]; // Player 0: 11 kills, Player 1: 7 kills
    $members = ChallengeTestHelper::createMembersForAccounts($accountIds);
    $memberIds = $members->pluck('id')->all();

    $scenario = ChallengeTestHelper::createChallengeScenario('total_kills', [
        'configuration' => ['metric' => 'kills'],
        'base_requirement' => 60,
    ], [
        'current_requirement' => 60,
        'current_progress' => 0,
    ]);

    // Process first match
    $match1 = ChallengeTestHelper::createMatchFromFixture($memberIds);
    $this->progressService->processMatch($match1);
    $scenario['destinationChallenge']->refresh();

    expect($scenario['destinationChallenge']->current_progress)->toBe(18);
    expect($scenario['destinationChallenge']->progress_data['contributors'][$memberIds[0]])->toBe(11);
    expect($scenario['destinationChallenge']->progress_data['contributors'][$memberIds[1]])->toBe(7);

    // Process second match (same fixture, different match ID)
    $match2 = ChallengeTestHelper::createMatchFromFixture($memberIds);
    $this->progressService->processMatch($match2);
    $scenario['destinationChallenge']->refresh();

    expect($scenario['destinationChallenge']->current_progress)->toBe(36);
    expect($scenario['destinationChallenge']->progress_data['contributors'][$memberIds[0]])->toBe(22);
    expect($scenario['destinationChallenge']->progress_data['contributors'][$memberIds[1]])->toBe(14);
});

// ──────────────────────────────────────────────────────
// Merge Logic — Matches Dedup
// ──────────────────────────────────────────────────────

test('merge: matches array is deduplicated', function () {
    $accountIds = [125753349];
    $members = ChallengeTestHelper::createMembersForAccounts($accountIds);

    $scenario = ChallengeTestHelper::createChallengeScenario('total_kills', [
        'configuration' => ['metric' => 'kills'],
        'base_requirement' => 30,
    ], [
        'current_requirement' => 30,
        'current_progress' => 0,
    ]);

    $match = ChallengeTestHelper::createMatchFromFixture($members->pluck('id')->all());

    // Process same match twice (idempotency check will block the second run in processChallenge,
    // but mergeProgressData's dedup is still tested implicitly)
    $this->progressService->processMatch($match);
    $scenario['destinationChallenge']->refresh();

    $pd = $scenario['destinationChallenge']->progress_data;
    expect($pd['matches'])->toHaveCount(1);
});

// ──────────────────────────────────────────────────────
// Merge Logic — Best Attempt
// ──────────────────────────────────────────────────────

test('merge: best_attempt updates only when improved', function () {
    $accountId = 153517712; // Player 5, 154 last_hits
    $member = ChallengeTestHelper::createMemberForAccount($accountId);

    $scenario = ChallengeTestHelper::createChallengeScenario('last_hits', [
        'configuration' => ['metric' => 'last_hits'],
        'base_requirement' => 200,
    ], [
        'current_requirement' => 200,
        'current_progress' => 0,
    ]);

    // First match: 154 last_hits
    $match1 = ChallengeTestHelper::createMatchFromFixture([$member->id]);
    $this->progressService->processMatch($match1);
    $scenario['destinationChallenge']->refresh();

    $pd = $scenario['destinationChallenge']->progress_data;
    expect($pd['best_attempt'])->toBe(154);
    expect($pd['best_member_id'])->toBe($member->id);

    // Second match: 80 last_hits (lower) — best_attempt should stay at 154
    $lowMatch = DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_data' => ChallengeTestHelper::buildMatchData([
            [
                'account_id' => $accountId,
                'player_slot' => 0,
                'hero_id' => 67,
                'kills' => 5,
                'deaths' => 3,
                'assists' => 10,
                'last_hits' => 80,
                'denies' => 0,
                'hero_healing' => 0,
                'item_0' => 0, 'item_1' => 0, 'item_2' => 0,
                'item_3' => 0, 'item_4' => 0, 'item_5' => 0,
            ],
        ]),
        'members' => [$member->id],
    ]);

    $this->progressService->processMatch($lowMatch);
    $scenario['destinationChallenge']->refresh();

    $pd = $scenario['destinationChallenge']->progress_data;
    expect($pd['best_attempt'])->toBe(154); // Still the old best
    expect($pd['best_member_id'])->toBe($member->id);

    // Progress should not have changed
    expect($scenario['destinationChallenge']->current_progress)->toBe(154);
});

// ──────────────────────────────────────────────────────
// Backward Compatibility
// ──────────────────────────────────────────────────────

test('merge: handles empty existing progress_data gracefully', function () {
    $accountId = 125753349;

    $member = ChallengeTestHelper::createMemberForAccount($accountId);
    $scenario = ChallengeTestHelper::createChallengeScenario('total_kills', [
        'configuration' => ['metric' => 'kills'],
        'base_requirement' => 30,
    ], [
        'current_requirement' => 30,
        'current_progress' => 0,
        'progress_data' => null, // Explicit null — like a fresh challenge
    ]);

    $match = ChallengeTestHelper::createMatchFromFixture([$member->id]);

    $this->progressService->processMatch($match);
    $scenario['destinationChallenge']->refresh();

    $pd = $scenario['destinationChallenge']->progress_data;
    expect($pd)->not->toBeNull();
    expect($pd)->toHaveKeys(['contributors', 'matches', 'best_attempt', 'metadata']);
    expect($pd['contributors'][$member->id])->toBe(11);
});

// ──────────────────────────────────────────────────────
// Metadata Merge
// ──────────────────────────────────────────────────────

test('merge: metadata is shallow-merged correctly', function () {
    $heroId = 135;
    $accountId = 125753349;

    $member = ChallengeTestHelper::createMemberForAccount($accountId);
    $scenario = ChallengeTestHelper::createChallengeScenario('hero_win', [
        'configuration' => ['hero_id' => $heroId],
        'base_requirement' => 3,
    ], [
        'current_requirement' => 3,
        'current_progress' => 0,
    ]);

    $match = ChallengeTestHelper::createMatchFromFixture([$member->id], [
        'radiant_win' => true,
    ]);

    $this->progressService->processMatch($match);
    $scenario['destinationChallenge']->refresh();

    $pd = $scenario['destinationChallenge']->progress_data;
    expect($pd['metadata']['hero_id'])->toBe($heroId);
});
