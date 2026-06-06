<?php

use App\DataObjects\Challenges\EvaluationResult;
use App\Models\Challenge;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Services\ChallengeEvaluators\FastWinEvaluator;

beforeEach(function () {
    $this->evaluator = new FastWinEvaluator;
});

function fweMember(int $id, string $steamId): Member
{
    $member = new Member(['steam_id' => $steamId, 'name' => "Player{$id}"]);
    $member->id = $id;

    return $member;
}

function fweChallenge(string $code): Challenge
{
    $challenge = new Challenge(['code' => $code]);
    $challenge->id = 1;

    return $challenge;
}

function fweDestinationChallenge(Challenge $challenge, int $currentRequirement = 25, int $currentProgress = 0): DestinationChallenge
{
    $dc = new DestinationChallenge(['current_requirement' => $currentRequirement, 'current_progress' => $currentProgress]);
    $dc->setRelation('challenge', $challenge);

    return $dc;
}

function fweMatch(string $matchId, array $matchData, array $memberIds): DotaMatch
{
    $match = new DotaMatch([
        'match_id' => $matchId,
        'match_data' => $matchData,
        'members' => $memberIds,
    ]);
    $match->id = (int) $matchId;

    return $match;
}

// --- Winning Fast Match Passes ---

test('winning fast match passes', function () {
    $member = fweMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = fweMatch('8000000030', [
        'radiant_win' => true,
        'duration' => 1380, // 23 minutes
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 0, 'kills' => 5],
        ],
    ], [$member->id]);

    $challenge = fweChallenge('fast_win');
    $dc = fweDestinationChallenge($challenge, 25);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result)->toBeInstanceOf(EvaluationResult::class);
    expect($result->matched)->toBeTrue();
    expect($result->progressDelta)->toBe(1);
});

// --- Losing Fast Match Fails ---

test('losing fast match fails', function () {
    $member = fweMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = fweMatch('8000000031', [
        'radiant_win' => true,
        'duration' => 900, // 15 minutes — fast but lost
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 128, 'kills' => 5], // Dire = lost
        ],
    ], [$member->id]);

    $challenge = fweChallenge('fast_win');
    $dc = fweDestinationChallenge($challenge, 25);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->matched)->toBeFalse();
});

// --- Winning Slow Match Fails ---

test('winning slow match fails', function () {
    $member = fweMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = fweMatch('8000000032', [
        'radiant_win' => true,
        'duration' => 1800, // 30 minutes — too slow
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 0, 'kills' => 5],
        ],
    ], [$member->id]);

    $challenge = fweChallenge('fast_win');
    $dc = fweDestinationChallenge($challenge, 25);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->matched)->toBeFalse();
});

// --- Duration Exactly At Requirement ---

test('duration exactly at requirement passes', function () {
    $member = fweMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = fweMatch('8000000033', [
        'radiant_win' => true,
        'duration' => 1500, // exactly 25 minutes
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 0, 'kills' => 5],
        ],
    ], [$member->id]);

    $challenge = fweChallenge('fast_win');
    $dc = fweDestinationChallenge($challenge, 25);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->matched)->toBeTrue();
});

// --- Duration Stored in Metadata ---

test('duration metadata is stored', function () {
    $member = fweMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = fweMatch('8000000034', [
        'radiant_win' => true,
        'duration' => 1320, // 22 minutes
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 0, 'kills' => 5],
        ],
    ], [$member->id]);

    $challenge = fweChallenge('fast_win');
    $dc = fweDestinationChallenge($challenge, 25);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->progressData['metadata']['duration_minutes'])->toBe(22);
    expect($result->progressData['metadata']['duration_seconds'])->toBe(1320);
});

// --- Already Completed (Idempotency) ---

test('returns unmatched if already completed', function () {
    $member = fweMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = fweMatch('8000000035', [
        'radiant_win' => true,
        'duration' => 1200,
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 0, 'kills' => 5],
        ],
    ], [$member->id]);

    $challenge = fweChallenge('fast_win');
    $dc = fweDestinationChallenge($challenge, 25, 1); // already completed

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->matched)->toBeFalse();
});

// --- No Participating Members ---

test('returns unmatched when no participating members', function () {
    $match = fweMatch('8000000036', [
        'radiant_win' => true,
        'duration' => 1200,
        'players' => [],
    ], []);

    $challenge = fweChallenge('fast_win');
    $dc = fweDestinationChallenge($challenge, 25);

    $result = $this->evaluator->evaluate($dc, $match, collect([]));

    expect($result->matched)->toBeFalse();
});
