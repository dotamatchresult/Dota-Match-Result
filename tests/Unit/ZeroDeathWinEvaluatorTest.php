<?php

use App\DataObjects\Challenges\EvaluationResult;
use App\Models\Challenge;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Services\ChallengeEvaluators\ZeroDeathWinEvaluator;

beforeEach(function () {
    $this->evaluator = new ZeroDeathWinEvaluator;
});

function zdweMember(int $id, string $steamId): Member
{
    $member = new Member(['steam_id' => $steamId, 'name' => "Player{$id}"]);
    $member->id = $id;

    return $member;
}

function zdweChallenge(string $code): Challenge
{
    $challenge = new Challenge(['code' => $code]);
    $challenge->id = 1;

    return $challenge;
}

function zdweDestinationChallenge(Challenge $challenge): DestinationChallenge
{
    $dc = new DestinationChallenge(['current_requirement' => 1]);
    $dc->setRelation('challenge', $challenge);

    return $dc;
}

function zdweMatch(string $matchId, array $matchData, array $memberIds): DotaMatch
{
    $match = new DotaMatch([
        'match_id' => $matchId,
        'match_data' => $matchData,
        'members' => $memberIds,
    ]);
    $match->id = (int) $matchId;

    return $match;
}

// --- Win + 0 Deaths Passes ---

test('win with zero deaths passes', function () {
    $member = zdweMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = zdweMatch('8000000040', [
        'radiant_win' => true,
        'players' => [
            [
                'account_id' => $accountId,
                'player_slot' => 0,
                'hero_id' => 14,
                'kills' => 5,
                'deaths' => 0,
                'assists' => 10,
            ],
        ],
    ], [$member->id]);

    $challenge = zdweChallenge('zero_death_win');
    $dc = zdweDestinationChallenge($challenge);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result)->toBeInstanceOf(EvaluationResult::class);
    expect($result->matched)->toBeTrue();
    expect($result->progressDelta)->toBe(1);
    expect($result->contributors)->toHaveCount(1);
    expect($result->contributors[0]['member_id'])->toBe($member->id);
});

// --- Win + Deaths Fails ---

test('win with deaths fails', function () {
    $member = zdweMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = zdweMatch('8000000041', [
        'radiant_win' => true,
        'players' => [
            [
                'account_id' => $accountId,
                'player_slot' => 0,
                'hero_id' => 14,
                'kills' => 10,
                'deaths' => 3,
                'assists' => 5,
            ],
        ],
    ], [$member->id]);

    $challenge = zdweChallenge('zero_death_win');
    $dc = zdweDestinationChallenge($challenge);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->matched)->toBeFalse();
});

// --- Loss + 0 Deaths Fails ---

test('loss with zero deaths fails', function () {
    $member = zdweMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = zdweMatch('8000000042', [
        'radiant_win' => true,
        'players' => [
            [
                'account_id' => $accountId,
                'player_slot' => 128, // Dire = lost
                'hero_id' => 14,
                'kills' => 0,
                'deaths' => 0,
                'assists' => 2,
            ],
        ],
    ], [$member->id]);

    $challenge = zdweChallenge('zero_death_win');
    $dc = zdweDestinationChallenge($challenge);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member]));

    expect($result->matched)->toBeFalse();
});

// --- Multiple Qualifying Members ---

test('multiple qualifying members in same match', function () {
    $member1 = zdweMember(1, '76561197960265729');
    $member2 = zdweMember(2, '76561197960265730');
    $accountId1 = Member::convertSteamIdToAccountId($member1->steam_id);
    $accountId2 = Member::convertSteamIdToAccountId($member2->steam_id);

    $match = zdweMatch('8000000043', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId1, 'player_slot' => 0, 'hero_id' => 14, 'kills' => 3, 'deaths' => 0, 'assists' => 5],
            ['account_id' => $accountId2, 'player_slot' => 1, 'hero_id' => 22, 'kills' => 8, 'deaths' => 0, 'assists' => 3],
        ],
    ], [$member1->id, $member2->id]);

    $challenge = zdweChallenge('zero_death_win');
    $dc = zdweDestinationChallenge($challenge);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member1, $member2]));

    expect($result->matched)->toBeTrue();
    expect($result->progressDelta)->toBe(2);
    expect($result->contributors)->toHaveCount(2);
});

// --- Contributors Map ---

test('contributors map is built correctly', function () {
    $member1 = zdweMember(1, '76561197960265729');
    $member2 = zdweMember(2, '76561197960265730');
    $accountId1 = Member::convertSteamIdToAccountId($member1->steam_id);
    $accountId2 = Member::convertSteamIdToAccountId($member2->steam_id);

    $match = zdweMatch('8000000044', [
        'radiant_win' => true,
        'players' => [
            ['account_id' => $accountId1, 'player_slot' => 0, 'hero_id' => 14, 'kills' => 3, 'deaths' => 0, 'assists' => 5],
            ['account_id' => $accountId2, 'player_slot' => 1, 'hero_id' => 22, 'kills' => 8, 'deaths' => 0, 'assists' => 3],
        ],
    ], [$member1->id, $member2->id]);

    $challenge = zdweChallenge('zero_death_win');
    $dc = zdweDestinationChallenge($challenge);

    $result = $this->evaluator->evaluate($dc, $match, collect([$member1, $member2]));

    expect($result->progressData['contributors'])->toHaveKeys([$member1->id, $member2->id]);
    expect($result->progressData['contributors'][$member1->id])->toBe(1);
    expect($result->progressData['contributors'][$member2->id])->toBe(1);
});

// --- No Participating Members ---

test('returns unmatched when no participating members', function () {
    $match = zdweMatch('8000000045', [
        'radiant_win' => true,
        'players' => [],
    ], []);

    $challenge = zdweChallenge('zero_death_win');
    $dc = zdweDestinationChallenge($challenge);

    $result = $this->evaluator->evaluate($dc, $match, collect([]));

    expect($result->matched)->toBeFalse();
});
