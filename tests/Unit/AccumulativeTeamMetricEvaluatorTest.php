<?php

use App\DataObjects\Challenges\EvaluationResult;
use App\Models\Challenge;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Services\ChallengeEvaluators\AccumulativeTeamMetricEvaluator;

beforeEach(function () {
    $this->evaluator = new AccumulativeTeamMetricEvaluator;
});

function atmeMember(int $id, string $steamId): Member
{
    $member = new Member(['steam_id' => $steamId, 'name' => "Player{$id}"]);
    $member->id = $id;

    return $member;
}

function atmeChallenge(string $code, ?array $configuration = null): Challenge
{
    $challenge = new Challenge(['code' => $code, 'configuration' => $configuration]);
    $challenge->id = 1;

    return $challenge;
}

function atmeDestinationChallenge(Challenge $challenge, int $currentRequirement = 30, array $progressData = []): DestinationChallenge
{
    $dc = new DestinationChallenge(['current_requirement' => $currentRequirement, 'progress_data' => $progressData]);
    $dc->setRelation('challenge', $challenge);

    return $dc;
}

function atmeMatch(string $matchId, array $matchData, array $memberIds): DotaMatch
{
    $match = new DotaMatch([
        'match_id' => $matchId,
        'match_data' => $matchData,
        'members' => $memberIds,
    ]);
    $match->id = (int) $matchId;

    return $match;
}

// --- Basic Summation ---

test('sums contributor kills correctly', function () {
    $member1 = atmeMember(1, '76561197960265729');
    $member2 = atmeMember(2, '76561197960265730');
    $accountId1 = Member::convertSteamIdToAccountId($member1->steam_id);
    $accountId2 = Member::convertSteamIdToAccountId($member2->steam_id);

    $match = atmeMatch('8000000001', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId1, 'player_slot' => 0, 'kills' => 12],
            ['account_id' => $accountId2, 'player_slot' => 1, 'kills' => 8],
        ],
    ], [$member1->id, $member2->id]);

    $challenge = atmeChallenge('total_kills', ['metric' => 'kills']);
    $dc = atmeDestinationChallenge($challenge, 30);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member1, $member2]));

    expect($result)->toBeInstanceOf(EvaluationResult::class);
    expect($result->matched)->toBeTrue();
    expect($result->progressDelta)->toBe(20); // 12 + 8
    expect($result->contributors)->toHaveCount(2);
    expect($result->contributors[0]['member_id'])->toBe($member1->id);
    expect($result->contributors[0]['value'])->toBe(12);
    expect($result->contributors[1]['member_id'])->toBe($member2->id);
    expect($result->contributors[1]['value'])->toBe(8);
});

// --- Zero Total ---

test('returns unmatched when team total is zero', function () {
    $member = atmeMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = atmeMatch('8000000002', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 0, 'kills' => 0],
        ],
    ], [$member->id]);

    $challenge = atmeChallenge('total_kills', ['metric' => 'kills']);
    $dc = atmeDestinationChallenge($challenge, 30);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->matched)->toBeFalse();
});

// --- No Participating Members ---

test('returns unmatched when no participating members', function () {
    $match = atmeMatch('8000000003', [
        'radiant_win' => true,
        'players' => [],
    ], []);

    $challenge = atmeChallenge('total_kills', ['metric' => 'kills']);
    $dc = atmeDestinationChallenge($challenge, 30);

    $result = $this->evaluator->evaluate($dc, $match, collect([]));

    expect($result->matched)->toBeFalse();
});

// --- Missing Metric Config ---

test('returns unmatched when metric configuration is missing', function () {
    $member = atmeMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = atmeMatch('8000000004', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 0, 'kills' => 10],
        ],
    ], [$member->id]);

    $challenge = atmeChallenge('total_kills', null);
    $dc = atmeDestinationChallenge($challenge, 30);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->matched)->toBeFalse();
});

// --- Different Metric (hero_healing) ---

test('sums hero_healing correctly', function () {
    $member1 = atmeMember(1, '76561197960265729');
    $member2 = atmeMember(2, '76561197960265730');
    $accountId1 = Member::convertSteamIdToAccountId($member1->steam_id);
    $accountId2 = Member::convertSteamIdToAccountId($member2->steam_id);

    $match = atmeMatch('8000000005', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId1, 'player_slot' => 0, 'hero_healing' => 5000],
            ['account_id' => $accountId2, 'player_slot' => 1, 'hero_healing' => 3000],
        ],
    ], [$member1->id, $member2->id]);

    $challenge = atmeChallenge('total_heal', ['metric' => 'hero_healing']);
    $dc = atmeDestinationChallenge($challenge, 10000);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member1, $member2]));

    expect($result->matched)->toBeTrue();
    expect($result->progressDelta)->toBe(8000);
});

// --- Members Not in Match ---

test('skips members not in match', function () {
    $member = atmeMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = atmeMatch('8000000006', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 0, 'denies' => 5],
        ],
    ], [$member->id]);

    $challenge = atmeChallenge('total_denies', ['metric' => 'denies']);
    $dc = atmeDestinationChallenge($challenge, 20);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->matched)->toBeTrue();
    expect($result->progressDelta)->toBe(5);
});

// --- Contributors Map ---

test('contributors map has member_id keys with values', function () {
    $member1 = atmeMember(1, '76561197960265729');
    $member2 = atmeMember(2, '76561197960265730');
    $accountId1 = Member::convertSteamIdToAccountId($member1->steam_id);
    $accountId2 = Member::convertSteamIdToAccountId($member2->steam_id);

    $match = atmeMatch('8000000007', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId1, 'player_slot' => 0, 'kills' => 7],
            ['account_id' => $accountId2, 'player_slot' => 1, 'kills' => 3],
        ],
    ], [$member1->id, $member2->id]);

    $challenge = atmeChallenge('total_kills', ['metric' => 'kills']);
    $dc = atmeDestinationChallenge($challenge, 30);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member1, $member2]));

    expect($result->progressData['contributors'])->toHaveKeys([$member1->id, $member2->id]);
    expect($result->progressData['contributors'][$member1->id])->toBe(7);
    expect($result->progressData['contributors'][$member2->id])->toBe(3);
});

// --- Matches Array ---

test('includes match_id in progress data', function () {
    $member = atmeMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = atmeMatch('8000000008', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 0, 'kills' => 10],
        ],
    ], [$member->id]);

    $challenge = atmeChallenge('total_kills', ['metric' => 'kills']);
    $dc = atmeDestinationChallenge($challenge, 30);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->progressData['matches'])->toBe([8000000008]);
});
