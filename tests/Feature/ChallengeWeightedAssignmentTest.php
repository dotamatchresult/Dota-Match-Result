<?php

use App\Models\Challenge;
use App\Models\DestinationChallenge;
use App\Services\DailyChallenge\ChallengeAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\ChallengeTestHelper;

uses(RefreshDatabase::class);

beforeEach(function () {
    ChallengeTestHelper::seedChallengesIfNeeded();
    $this->assignmentService = app(ChallengeAssignmentService::class);
});

// ──────────────────────────────────────────────────────
// Weighted Distribution
// ──────────────────────────────────────────────────────

test('hero_win appears significantly more often than zero_death_win', function () {
    $destination = ChallengeTestHelper::createTestDestination();
    $heroWinCount = 0;
    $zeroDeathWinCount = 0;
    $iterations = 500;

    for ($i = 0; $i < $iterations; $i++) {
        // Clear previous assignments to avoid max-active and idempotency blocks
        DestinationChallenge::query()
            ->where('destination_id', $destination->id)
            ->delete();

        $result = $this->assignmentService->assignForDestination($destination);

        if ($result !== null) {
            $code = $result->challenge->code;
            if ($code === 'hero_win') {
                $heroWinCount++;
            } elseif ($code === 'zero_death_win') {
                $zeroDeathWinCount++;
            }
        }
    }

    // hero_win has weight 15, zero_death_win has weight 2
    // hero_win should appear at least 3x more often
    expect($heroWinCount)->toBeGreaterThan($zeroDeathWinCount * 3);
});

test('total_kills appears significantly more often than fast_win', function () {
    $destination = ChallengeTestHelper::createTestDestination();
    $totalKillsCount = 0;
    $fastWinCount = 0;
    $iterations = 500;

    for ($i = 0; $i < $iterations; $i++) {
        DestinationChallenge::query()
            ->where('destination_id', $destination->id)
            ->delete();

        $result = $this->assignmentService->assignForDestination($destination);

        if ($result !== null) {
            $code = $result->challenge->code;
            if ($code === 'total_kills') {
                $totalKillsCount++;
            } elseif ($code === 'fast_win') {
                $fastWinCount++;
            }
        }
    }

    // total_kills has weight 15, fast_win has weight 5
    // total_kills should appear at least 1.5x more often
    expect($totalKillsCount)->toBeGreaterThan((int) ($fastWinCount * 1.5));
});

test('weighted assignment roughly follows configured weights', function () {
    $destination = ChallengeTestHelper::createTestDestination();
    $counts = [];
    $iterations = 1000;

    for ($i = 0; $i < $iterations; $i++) {
        DestinationChallenge::query()
            ->where('destination_id', $destination->id)
            ->delete();

        $result = $this->assignmentService->assignForDestination($destination);

        if ($result !== null) {
            $code = $result->challenge->code;
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }
    }

    // Verify all 8 challenge codes appear at least once
    $allCodes = Challenge::query()->active()->pluck('code')->all();
    foreach ($allCodes as $code) {
        expect($counts[$code] ?? 0)->toBeGreaterThan(0, "Challenge code '{$code}' was never assigned in {$iterations} iterations");
    }

    // hero_win (w=15) and total_kills (w=15) should be top-tier
    expect($counts['hero_win'] + $counts['total_kills'])->toBeGreaterThan($counts['zero_death_win'] + $counts['fast_win']);
});

// ──────────────────────────────────────────────────────
// Edge Cases
// ──────────────────────────────────────────────────────

test('inactive challenges are never selected', function () {
    $destination = ChallengeTestHelper::createTestDestination();

    // Deactivate all challenges except one
    Challenge::query()->update(['is_active' => false]);
    $activeChallenge = Challenge::query()->first();
    $activeChallenge->update(['is_active' => true]);

    DestinationChallenge::query()
        ->where('destination_id', $destination->id)
        ->delete();

    $result = $this->assignmentService->assignForDestination($destination);

    if ($result !== null) {
        expect($result->challenge_id)->toBe($activeChallenge->id);
    } else {
        // If pool exhausted (cooldown on the only active), that's also valid
        expect(Challenge::query()->active()->count())->toBe(1);
    }
});

test('active code exclusion prevents duplicate assignment of same code', function () {
    $destination = ChallengeTestHelper::createTestDestination();

    // Create an active hero_win challenge
    $heroWinChallenge = Challenge::query()->where('code', 'hero_win')->first();
    DestinationChallenge::factory()->create([
        'destination_id' => $destination->id,
        'challenge_id' => $heroWinChallenge->id,
        'status' => 'active',
        'assigned_date' => now()->subDay()->toDateString(),
    ]);

    $iterations = 100;
    $heroWinAssigned = 0;

    for ($i = 0; $i < $iterations; $i++) {
        DestinationChallenge::query()
            ->where('destination_id', $destination->id)
            ->where('status', 'active')
            ->where('assigned_date', now()->toDateString())
            ->delete();

        $result = $this->assignmentService->assignForDestination($destination);

        if ($result !== null && $result->challenge->code === 'hero_win') {
            $heroWinAssigned++;
        }
    }

    // hero_win should never be assigned because one is already active
    expect($heroWinAssigned)->toBe(0);
});
