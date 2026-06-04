<?php

use App\DataObjects\Challenges\EvaluationResult;
use App\Models\Challenge;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Services\ChallengeEvaluators\HeroWinEvaluator;

beforeEach(function () {
    $this->evaluator = new HeroWinEvaluator;
});

/**
 * Create a Member instance without persisting to the database.
 */
function hewMember(int $id, string $steamId): Member
{
    $member = new Member(['steam_id' => $steamId, 'name' => "Player{$id}"]);
    $member->id = $id;

    return $member;
}

/**
 * Create a Challenge instance without persisting to the database.
 */
function hewChallenge(string $code, ?array $configuration = null): Challenge
{
    $challenge = new Challenge(['code' => $code, 'configuration' => $configuration]);
    $challenge->id = 1;

    return $challenge;
}

/**
 * Create a DestinationChallenge with a related Challenge, without persisting.
 */
function hewDestinationChallenge(Challenge $challenge, int $currentRequirement = 2): DestinationChallenge
{
    $dc = new DestinationChallenge(['current_requirement' => $currentRequirement]);
    $dc->setRelation('challenge', $challenge);

    return $dc;
}

/**
 * Create a DotaMatch with specific match_data and member IDs, without persisting.
 */
function hewMatch(string $matchId, array $matchData, array $memberIds): DotaMatch
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

test('qualifying member contributes', function () {
    $heroId = 14; // Pudge

    $member = hewMember(1, '76561197960265729');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = hewMatch('8000000001', [
        'radiant_win' => true,
        'players' => [
            [
                'account_id' => $accountId,
                'player_slot' => 0, // Radiant
                'hero_id' => $heroId,
                'kills' => 5,
                'deaths' => 2,
                'assists' => 10,
            ],
        ],
    ], [$member->id]);

    $challenge = hewChallenge('hero_win', ['hero_id' => $heroId]);
    $destinationChallenge = hewDestinationChallenge($challenge, 2);

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

// --- Wrong Hero ---

test('wrong hero is ignored', function () {
    $configuredHeroId = 14; // Pudge
    $playedHeroId = 22;     // Zeus

    $member = hewMember(2, '76561197960265730');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = hewMatch('8000000002', [
        'radiant_win' => true,
        'players' => [
            [
                'account_id' => $accountId,
                'player_slot' => 0,
                'hero_id' => $playedHeroId,
                'kills' => 5,
                'deaths' => 2,
                'assists' => 10,
            ],
        ],
    ], [$member->id]);

    $challenge = hewChallenge('hero_win', ['hero_id' => $configuredHeroId]);
    $destinationChallenge = hewDestinationChallenge($challenge);

    $result = $this->evaluator->evaluate(
        $destinationChallenge,
        $match,
        collect([$member])
    );

    expect($result->matched)->toBeFalse();
    expect($result->progressDelta)->toBe(0);
});

// --- Losing Match ---

test('losing match is ignored', function () {
    $heroId = 14; // Pudge

    $member = hewMember(3, '76561197960265731');
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $match = hewMatch('8000000003', [
        'radiant_win' => false, // Dire won
        'players' => [
            [
                'account_id' => $accountId,
                'player_slot' => 0, // Radiant — lost
                'hero_id' => $heroId,
                'kills' => 3,
                'deaths' => 5,
                'assists' => 8,
            ],
        ],
    ], [$member->id]);

    $challenge = hewChallenge('hero_win', ['hero_id' => $heroId]);
    $destinationChallenge = hewDestinationChallenge($challenge);

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
    $heroId = 14; // Pudge

    $alice = hewMember(4, '76561197960265732');
    $bob = hewMember(5, '76561197960265733');
    $aliceAccountId = Member::convertSteamIdToAccountId($alice->steam_id);
    $bobAccountId = Member::convertSteamIdToAccountId($bob->steam_id);

    $match = hewMatch('8000000004', [
        'radiant_win' => true,
        'players' => [
            [
                'account_id' => $aliceAccountId,
                'player_slot' => 0,
                'hero_id' => $heroId,
                'kills' => 5,
                'deaths' => 1,
                'assists' => 9,
            ],
            [
                'account_id' => $bobAccountId,
                'player_slot' => 1,
                'hero_id' => $heroId,
                'kills' => 3,
                'deaths' => 2,
                'assists' => 12,
            ],
        ],
    ], [$alice->id, $bob->id]);

    $challenge = hewChallenge('hero_win', ['hero_id' => $heroId]);
    $destinationChallenge = hewDestinationChallenge($challenge);

    $result = $this->evaluator->evaluate(
        $destinationChallenge,
        $match,
        collect([$alice, $bob])
    );

    expect($result->matched)->toBeTrue();
    expect($result->progressDelta)->toBe(2);
    expect($result->contributors)->toHaveCount(2);
});

// --- No Destination Members ---

test('no destination members results in no match', function () {
    $heroId = 14;

    $challenge = hewChallenge('hero_win', ['hero_id' => $heroId]);
    $destinationChallenge = hewDestinationChallenge($challenge);

    $match = hewMatch('8000000005', [
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
