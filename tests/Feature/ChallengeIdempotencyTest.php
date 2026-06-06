<?php

use App\Jobs\EvaluateChallengesJob;
use App\Models\ChallengeEvent;
use App\Models\ChallengeNotification;
use App\Services\DailyChallenge\ChallengeProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\ChallengeTestHelper;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->destination = ChallengeTestHelper::getWhatsAppDestination();
    $this->progressService = app(ChallengeProgressService::class);
});

// ──────────────────────────────────────────────────────
// ProgressService Idempotency
// ──────────────────────────────────────────────────────

test('processing same match twice does not duplicate progress', function () {
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

    // Process first time
    $this->progressService->processMatch($match);
    $scenario['destinationChallenge']->refresh();
    expect($scenario['destinationChallenge']->current_progress)->toBe(1);

    // Process second time — should be no-op
    $this->progressService->processMatch($match);
    $scenario['destinationChallenge']->refresh();

    expect($scenario['destinationChallenge']->current_progress)->toBe(1);

    // Only one progress event
    $progressEvents = ChallengeEvent::query()
        ->where('destination_challenge_id', $scenario['destinationChallenge']->id)
        ->where('type', 'progress')
        ->count();
    expect($progressEvents)->toBe(1);
});

test('processing same match twice does not duplicate completion', function () {
    $heroId = 135;
    $accountId = 125753349;

    $member = ChallengeTestHelper::createMemberForAccount($accountId);
    $scenario = ChallengeTestHelper::createChallengeScenario('hero_win', [
        'configuration' => ['hero_id' => $heroId],
        'base_requirement' => 1,
    ], [
        'current_requirement' => 1,
        'current_progress' => 0,
    ]);

    $match = ChallengeTestHelper::createMatchFromFixture([$member->id], [
        'radiant_win' => true,
    ]);

    // Process first time → completes
    $this->progressService->processMatch($match);
    $scenario['destinationChallenge']->refresh();
    expect($scenario['destinationChallenge']->status)->toBe('completed');

    // Process second time
    $this->progressService->processMatch($match);
    $scenario['destinationChallenge']->refresh();

    // Still completed, no duplicate events
    expect($scenario['destinationChallenge']->status)->toBe('completed');

    $completedEvents = ChallengeEvent::query()
        ->where('destination_challenge_id', $scenario['destinationChallenge']->id)
        ->where('type', 'completed')
        ->count();
    expect($completedEvents)->toBe(1);

    $completedNotifications = ChallengeNotification::query()
        ->where('destination_challenge_id', $scenario['destinationChallenge']->id)
        ->where('type', 'completed')
        ->count();
    expect($completedNotifications)->toBe(1);
});

test('processing match after completion does not add progress', function () {
    $heroId = 135;
    $accountId = 125753349;

    $member = ChallengeTestHelper::createMemberForAccount($accountId);
    $scenario = ChallengeTestHelper::createChallengeScenario('hero_win', [
        'configuration' => ['hero_id' => $heroId],
        'base_requirement' => 1,
    ], [
        'current_requirement' => 1,
        'current_progress' => 0,
    ]);

    $match1 = ChallengeTestHelper::createMatchFromFixture([$member->id], [
        'radiant_win' => true,
    ]);

    // Complete the challenge
    $this->progressService->processMatch($match1);
    $scenario['destinationChallenge']->refresh();
    expect($scenario['destinationChallenge']->status)->toBe('completed');

    $progressBefore = $scenario['destinationChallenge']->current_progress;

    // Try another match (different match_id — won't be caught by idempotency check on match_id,
    // but challenge is completed so processDestination won't pick it up)
    $match2 = ChallengeTestHelper::createMatchFromFixture([$member->id], [
        'radiant_win' => true,
    ]);

    $this->progressService->processMatch($match2);
    $scenario['destinationChallenge']->refresh();

    expect($scenario['destinationChallenge']->current_progress)->toBe($progressBefore);
});

// ──────────────────────────────────────────────────────
// EvaluateChallengesJob Idempotency
// ──────────────────────────────────────────────────────

test('dispatch job twice for same match is safe', function () {
    Queue::fake();

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

    // Dispatch job twice
    $job1 = new EvaluateChallengesJob($match);
    $job2 = new EvaluateChallengesJob($match);

    $job1->handle($this->progressService);
    $job2->handle($this->progressService);

    $scenario['destinationChallenge']->refresh();

    expect($scenario['destinationChallenge']->current_progress)->toBe(1);

    $progressEvents = ChallengeEvent::query()
        ->where('destination_challenge_id', $scenario['destinationChallenge']->id)
        ->where('type', 'progress')
        ->count();
    expect($progressEvents)->toBe(1);
});

// ──────────────────────────────────────────────────────
// Multiple Matches, Mixed Duplicates
// ──────────────────────────────────────────────────────

test('only new matches contribute when some are duplicates', function () {
    $accountIds = [125753349, 225621471];
    $members = ChallengeTestHelper::createMembersForAccounts($accountIds);
    $memberIds = $members->pluck('id')->all();

    $scenario = ChallengeTestHelper::createChallengeScenario('total_kills', [
        'configuration' => ['metric' => 'kills'],
        'base_requirement' => 60,
    ], [
        'current_requirement' => 60,
        'current_progress' => 0,
    ]);

    // Match 1: 18 kills
    $match1 = ChallengeTestHelper::createMatchFromFixture($memberIds);
    $this->progressService->processMatch($match1);
    expect($scenario['destinationChallenge']->fresh()->current_progress)->toBe(18);

    // Match 1 again: idempotent, no change
    $this->progressService->processMatch($match1);
    expect($scenario['destinationChallenge']->fresh()->current_progress)->toBe(18);

    // Match 2: another 18 kills → total 36
    $match2 = ChallengeTestHelper::createMatchFromFixture($memberIds);
    $this->progressService->processMatch($match2);
    expect($scenario['destinationChallenge']->fresh()->current_progress)->toBe(36);

    // Match 1 again: still idempotent
    $this->progressService->processMatch($match1);
    expect($scenario['destinationChallenge']->fresh()->current_progress)->toBe(36);

    // 2 unique matches in progress_data
    $pd = $scenario['destinationChallenge']->fresh()->progress_data;
    expect($pd['matches'])->toHaveCount(2);
});
