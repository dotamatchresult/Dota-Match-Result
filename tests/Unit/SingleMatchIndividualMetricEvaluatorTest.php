<?php

use App\Models\Challenge;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Services\ChallengeEvaluators\SingleMatchIndividualMetricEvaluator;

beforeEach(function () {
    $this->evaluator = new SingleMatchIndividualMetricEvaluator;
});

function smimeMember(int $id, string $steamId): Member
{
    $member = new Member(['steam_id' => $steamId, 'name' => "Player{$id}"]);
    $member->id = $id;

    return $member;
}

function smimeChallenge(string $code, ?array $configuration = null): Challenge
{
    $challenge = new Challenge(['code' => $code, 'configuration' => $configuration]);
    $challenge->id = 1;

    return $challenge;
}

function smimeDestinationChallenge(Challenge $challenge, int $currentRequirement = 60, array $progressData = []): DestinationChallenge
{
    $dc = new DestinationChallenge(['current_requirement' => $currentRequirement, 'progress_data' => $progressData]);
    $dc->setRelation('challenge', $challenge);

    return $dc;
}

function smimeMatch(string $matchId, array $matchData, array $memberIds): DotaMatch
{
    $match = new DotaMatch([
        'match_id' => $matchId,
        'match_data' => $matchData,
        'members' => $memberIds,
    ]);
    $match->id = (int) $matchId;

    return $match;
}

// --- Highest Player Selected ---

test('selects highest player value', function () {
    $member1 = smimeMember(1, '76561197960265729');
    $member2 = smimeMember(2, '76561197960265730');
    $member3 = smimeMember(3, '76561197960265731');
    $accountId1 = Member::convertSteamIdToAccountId($member1->steam_id);
    $accountId2 = Member::convertSteamIdToAccountId($member2->steam_id);
    $accountId3 = Member::convertSteamIdToAccountId($member3->steam_id);

    $match = smimeMatch('8000000020', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId1, 'player_slot' => 0, 'last_hits' => 40],
            ['account_id' => $accountId2, 'player_slot' => 1, 'last_hits' => 80],
            ['account_id' => $accountId3, 'player_slot' => 2, 'last_hits' => 30],
        ],
    ], [$member1->id, $member2->id, $member3->id]);

    $challenge = smimeChallenge('last_hits', ['metric' => 'last_hits']);
    $dc = smimeDestinationChallenge($challenge, 60);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member1, $member2, $member3]));

    expect($result->matched)->toBeTrue();
    expect($result->progressDelta)->toBe(80);
    expect($result->progressData['best_attempt'])->toBe(80);
    expect($result->progressData['best_member_id'])->toBe($member2->id);
});

// --- best_member_id Stored ---

test('best_member_id is stored', function () {
    $member1 = smimeMember(1, '76561197960265729');
    $member2 = smimeMember(2, '76561197960265730');
    $accountId1 = Member::convertSteamIdToAccountId($member1->steam_id);
    $accountId2 = Member::convertSteamIdToAccountId($member2->steam_id);

    $match = smimeMatch('8000000021', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId1, 'player_slot' => 0, 'last_hits' => 20],
            ['account_id' => $accountId2, 'player_slot' => 1, 'last_hits' => 65],
        ],
    ], [$member1->id, $member2->id]);

    $challenge = smimeChallenge('last_hits', ['metric' => 'last_hits']);
    $dc = smimeDestinationChallenge($challenge, 60);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member1, $member2]));

    expect($result->progressData['best_member_id'])->toBe($member2->id);
});

// --- best_match_id Stored ---

test('best_match_id is stored', function () {
    $member = smimeMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = smimeMatch('8000000022', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 0, 'last_hits' => 70],
        ],
    ], [$member->id]);

    $challenge = smimeChallenge('last_hits', ['metric' => 'last_hits']);
    $dc = smimeDestinationChallenge($challenge, 60);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->progressData['best_match_id'])->toBe(8000000022);
});

// --- Not Updated When Lower ---

test('returns unmatched when individual best does not beat previous best', function () {
    $member = smimeMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = smimeMatch('8000000023', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 0, 'last_hits' => 40],
        ],
    ], [$member->id]);

    $challenge = smimeChallenge('last_hits', ['metric' => 'last_hits']);
    $dc = smimeDestinationChallenge($challenge, 60, ['best_attempt' => 55]);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->matched)->toBeFalse();
});

// --- Completion Detection ---

test('progress delta is the improvement over previous best', function () {
    $member = smimeMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = smimeMatch('8000000024', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 0, 'last_hits' => 65],
        ],
    ], [$member->id]);

    $challenge = smimeChallenge('last_hits', ['metric' => 'last_hits']);
    $dc = smimeDestinationChallenge($challenge, 60, ['best_attempt' => 40]);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->matched)->toBeTrue();
    expect($result->progressDelta)->toBe(25); // 65 - 40
});

// --- Missing Metric Config ---

test('returns unmatched when metric configuration is missing', function () {
    $member = smimeMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = smimeMatch('8000000025', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 0, 'last_hits' => 70],
        ],
    ], [$member->id]);

    $challenge = smimeChallenge('last_hits', null);
    $dc = smimeDestinationChallenge($challenge, 60);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->matched)->toBeFalse();
});

// --- No Participating Members ---

test('returns unmatched when no participating members', function () {
    $match = smimeMatch('8000000026', [
        'radiant_win' => true,
        'players' => [],
    ], []);

    $challenge = smimeChallenge('last_hits', ['metric' => 'last_hits']);
    $dc = smimeDestinationChallenge($challenge, 60);

    $result = $this->evaluator->evaluate($dc, $match, collect([]));

    expect($result->matched)->toBeFalse();
});
