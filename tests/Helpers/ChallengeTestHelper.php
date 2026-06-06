<?php

namespace Tests\Helpers;

use App\Enums\DestinationType;
use App\Models\Challenge;
use App\Models\Destination;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;

/**
 * Reusable fixture infrastructure for challenge simulation tests.
 *
 * Provides one-liner methods to create complete challenge scenarios
 * using real OpenDota fixture data from docs/samples/.
 */
class ChallengeTestHelper
{
    /**
     * Cache loaded fixture data to avoid repeated file reads.
     *
     * @var array<string, array>
     */
    private static array $fixtureCache = [];

    // --- Fixture Loading ---

    /**
     * Load a JSON fixture from docs/samples/.
     *
     * @return array{players: array, radiant_win?: bool, duration?: int}
     */
    public static function loadFixture(string $name): array
    {
        if (! isset(self::$fixtureCache[$name])) {
            $path = base_path("docs/samples/{$name}");

            if (! file_exists($path)) {
                throw new \InvalidArgumentException("Fixture not found: {$path}");
            }

            self::$fixtureCache[$name] = json_decode(file_get_contents($path), true);
        }

        return self::$fixtureCache[$name];
    }

    /**
     * Load the standard unparsed match fixture.
     */
    public static function loadMatchFixture(): array
    {
        return self::loadFixture('match_unparsed.json');
    }

    /**
     * Load the parsed match fixture (teamfight data).
     */
    public static function loadParsedFixture(): array
    {
        return self::loadFixture('match_parsed.json');
    }

    // --- Member Creation ---

    /**
     * Create a Member from an OpenDota account_id.
     *
     * Converts 32-bit account_id to 64-bit Steam ID and creates a Member
     * associated with the given destination.
     */
    public static function createMemberForAccount(int $accountId, string $destinationCode = DestinationType::WhatsApp->value): Member
    {
        $steamId = Member::convertAccountIdToSteamId((string) $accountId);

        return Member::factory()->create([
            'steam_id' => $steamId,
            'destination' => $destinationCode,
        ]);
    }

    /**
     * Create Members from an array of account_ids.
     *
     * @param  int[]  $accountIds
     * @return \Illuminate\Support\Collection<int, Member>
     */
    public static function createMembersForAccounts(array $accountIds, string $destinationCode = DestinationType::WhatsApp->value): \Illuminate\Support\Collection
    {
        return collect($accountIds)->map(fn (int $accountId) => self::createMemberForAccount($accountId, $destinationCode));
    }

    // --- Match Creation ---

    /**
     * Create a DotaMatch from the standard fixture, mapped to specific members.
     *
     * @param  int[]  $memberIds  Member IDs to associate with the match
     * @param  array  $overrides  Keys to override in match_data (e.g., radiant_win, duration)
     */
    public static function createMatchFromFixture(array $memberIds, array $overrides = []): DotaMatch
    {
        $fixture = self::loadMatchFixture();

        $matchData = $fixture;

        // Apply top-level overrides
        foreach ($overrides as $key => $value) {
            if ($key === 'players') {
                $matchData['players'] = $value;
            } else {
                $matchData[$key] = $value;
            }
        }

        // Also apply overrides to each player's top-level data
        // (some fixture data like radiant_win and duration are per-player)
        foreach ($matchData['players'] as $i => $player) {
            foreach ($overrides as $key => $value) {
                if ($key !== 'players' && array_key_exists($key, $player)) {
                    $matchData['players'][$i][$key] = $value;
                }
            }
        }

        return DotaMatch::create([
            'match_id' => (string) fake()->unique()->randomNumber(9, true),
            'match_data' => $matchData,
            'members' => $memberIds,
        ]);
    }

    /**
     * Build custom match_data with specific players array.
     *
     * Useful for constructing minimal match payloads without loading the full fixture.
     *
     * @param  array  $players  Array of player arrays
     * @param  array  $overrides  Top-level overrides (radiant_win, duration, game_mode)
     */
    public static function buildMatchData(array $players, array $overrides = []): array
    {
        return array_merge([
            'radiant_win' => true,
            'game_mode' => 22,
            'duration' => 1800,
            'players' => $players,
        ], $overrides);
    }

    // --- Destination Setup ---

    /**
     * Get or create the WhatsApp test destination.
     */
    public static function getWhatsAppDestination(): Destination
    {
        return Destination::query()->firstOrCreate(
            ['code' => Destination::CODE_WHATSAPP],
            ['name' => 'WhatsApp', 'target' => '628123456789']
        );
    }

    /**
     * Create a unique test destination.
     */
    public static function createTestDestination(): Destination
    {
        return Destination::factory()->create([
            'code' => 'test_dest_'.fake()->unique()->randomNumber(6, true),
            'target' => '628123456789',
        ]);
    }

    // --- Challenge Scenario Setup ---

    /**
     * Create a complete challenge scenario in one call.
     *
     * Creates Destination, Challenge (via factory), and an active DestinationChallenge.
     *
     * @return array{destination: Destination, challenge: Challenge, destinationChallenge: DestinationChallenge}
     */
    public static function createChallengeScenario(string $challengeCode, array $challengeAttrs = [], array $dcAttrs = [], ?Destination $destination = null): array
    {
        $destination ??= self::getWhatsAppDestination();

        $challenge = Challenge::factory()->create(array_merge([
            'code' => $challengeCode,
            'is_active' => true,
        ], $challengeAttrs));

        $destinationChallenge = DestinationChallenge::factory()->create(array_merge([
            'destination_id' => $destination->id,
            'challenge_id' => $challenge->id,
            'status' => 'active',
        ], $dcAttrs));

        return [
            'destination' => $destination,
            'challenge' => $challenge,
            'destinationChallenge' => $destinationChallenge,
        ];
    }

    // --- Seed Helpers ---

    /**
     * Seed the challenge catalog if not already seeded.
     */
    public static function seedChallengesIfNeeded(): void
    {
        if (Challenge::count() === 0) {
            \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'ChallengeSeeder']);
        }
    }

    /**
     * Get player data from the fixture by index.
     */
    public static function getFixturePlayer(int $index): array
    {
        $fixture = self::loadMatchFixture();

        return $fixture['players'][$index];
    }

    /**
     * Get all account_ids from the fixture that have valid account_id values.
     *
     * @return int[]
     */
    public static function getFixtureAccountIds(): array
    {
        $fixture = self::loadMatchFixture();
        $ids = [];

        foreach ($fixture['players'] as $player) {
            if (! empty($player['account_id'])) {
                $ids[] = (int) $player['account_id'];
            }
        }

        return $ids;
    }
}
