<?php

use App\Models\Challenge;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Services\ChallengeEvaluators\SingleMatchTeamMetricEvaluator;

beforeEach(function () {
    $this->evaluator = new SingleMatchTeamMetricEvaluator;
});

function smtmeMember(int $id, string $steamId): Member
{
    $member = new Member(['steam_id' => $steamId, 'name' => "Player{$id}"]);
    $member->id = $id;

    return $member;
}

function smtmeChallenge(string $code, ?array $configuration = null): Challenge
{
    $challenge = new Challenge(['code' => $code, 'configuration' => $configuration]);
    $challenge->id = 1;

    return $challenge;
}

function smtmeDestinationChallenge(Challenge $challenge, int $currentRequirement = 150, array $progressData = []): DestinationChallenge
{
    $dc = new DestinationChallenge(['current_requirement' => $currentRequirement, 'progress_data' => $progressData]);
    $dc->setRelation('challenge', $challenge);

    return $dc;
}

function smtmeMatch(string $matchId, array $matchData, array $memberIds): DotaMatch
{
    $match = new DotaMatch([
        'match_id' => $matchId,
        'match_data' => $matchData,
        'members' => $memberIds,
    ]);
    $match->id = (int) $matchId;

    return $match;
}

// --- Team Total Calculation ---

test('calculates team total correctly', function () {
    $member1 = smtmeMember(1, '76561197960265729');
    $member2 = smtmeMember(2, '76561197960265730');
    $accountId1 = Member::convertSteamIdToAccountId($member1->steam_id);
    $accountId2 = Member::convertSteamIdToAccountId($member2->steam_id);

    $match = smtmeMatch('8000000010', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId1, 'player_slot' => 0, 'last_hits' => 80],
            ['account_id' => $accountId2, 'player_slot' => 1, 'last_hits' => 70],
        ],
    ], [$member1->id, $member2->id]);

    $challenge = smtmeChallenge('last_hits_team', ['metric' => 'last_hits']);
    $dc = smtmeDestinationChallenge($challenge, 150);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member1, $member2]));

    expect($result->matched)->toBeTrue();
    expect($result->progressDelta)->toBe(150); // 80 + 70
});

// --- best_attempt Updates ---

test('best_attempt is set on first match', function () {
    $member = smtmeMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = smtmeMatch('8000000011', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 0, 'last_hits' => 100],
        ],
    ], [$member->id]);

    $challenge = smtmeChallenge('last_hits_team', ['metric' => 'last_hits']);
    $dc = smtmeDestinationChallenge($challenge, 150);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->progressData['best_attempt'])->toBe(100);
    expect($result->progressData['best_match_id'])->toBe(8000000011);
});

// --- best_attempt Not Updated When Lower ---

test('returns unmatched when team total does not beat previous best', function () {
    $member = smtmeMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = smtmeMatch('8000000012', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 0, 'last_hits' => 60],
        ],
    ], [$member->id]);

    $challenge = smtmeChallenge('last_hits_team', ['metric' => 'last_hits']);
    $dc = smtmeDestinationChallenge($challenge, 150, ['best_attempt' => 120]);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->matched)->toBeFalse();
});

// --- Completion Detection ---

test('progress delta pushes current_progress to meet requirement', function () {
    $member = smtmeMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = smtmeMatch('8000000013', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 0, 'last_hits' => 160],
        ],
    ], [$member->id]);

    $challenge = smtmeChallenge('last_hits_team', ['metric' => 'last_hits']);
    $dc = smtmeDestinationChallenge($challenge, 150, ['best_attempt' => 80]);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->matched)->toBeTrue();
    expect($result->progressDelta)->toBe(80); // 160 - 80
});

// --- Contributor Storage ---

test('stores contributors in progress data', function () {
    $member1 = smtmeMember(1, '76561197960265729');
    $member2 = smtmeMember(2, '76561197960265730');
    $accountId1 = Member::convertSteamIdToAccountId($member1->steam_id);
    $accountId2 = Member::convertSteamIdToAccountId($member2->steam_id);

    $match = smtmeMatch('8000000014', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId1, 'player_slot' => 0, 'last_hits' => 90],
            ['account_id' => $accountId2, 'player_slot' => 1, 'last_hits' => 50],
        ],
    ], [$member1->id, $member2->id]);

    $challenge = smtmeChallenge('last_hits_team', ['metric' => 'last_hits']);
    $dc = smtmeDestinationChallenge($challenge, 150);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member1, $member2]));

    expect($result->progressData['contributors'])->toHaveKeys([$member1->id, $member2->id]);
    expect($result->progressData['contributors'][$member1->id])->toBe(90);
    expect($result->progressData['contributors'][$member2->id])->toBe(50);
});

// --- Missing Metric Config ---

test('returns unmatched when metric configuration is missing', function () {
    $member = smtmeMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = smtmeMatch('8000000015', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId, 'player_slot' => 0, 'last_hits' => 100],
        ],
    ], [$member->id]);

    $challenge = smtmeChallenge('last_hits_team', null);
    $dc = smtmeDestinationChallenge($challenge, 150);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->matched)->toBeFalse();
});

// --- No Participating Members ---

test('returns unmatched when no participating members', function () {
    $match = smtmeMatch('8000000016', [
        'radiant_win' => true,
        'players' => [],
    ], []);

    $challenge = smtmeChallenge('last_hits_team', ['metric' => 'last_hits']);
    $dc = smtmeDestinationChallenge($challenge, 150);

    $result = $this->evaluator->evaluate($dc, $match, collect([]));

    expect($result->matched)->toBeFalse();
});
