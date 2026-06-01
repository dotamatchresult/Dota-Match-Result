<?php

use App\Jobs\ProcessMatchNotification;
use App\Models\Destination;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Models\Reminder;
use App\Services\FonnteService;
use App\Services\HeroService;
use App\Services\SteamApiService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

test('members can be created with steam id and name', function () {
    $member = Member::factory()->create([
        'steam_id' => '76561198012345678',
        'name' => 'TestPlayer',
    ]);

    expect($member->steam_id)->toBe('76561198012345678')
        ->and($member->name)->toBe('TestPlayer');
});

test('dota match can be created with match data', function () {
    $match = DotaMatch::factory()->create([
        'match_id' => '8179561591',
        'members' => [1, 2],
    ]);

    expect($match->match_id)->toBe('8179561591')
        ->and($match->members)->toBeArray()
        ->and($match->members)->toHaveCount(2);
});

test('dota match prevents duplicate match_id', function () {
    DotaMatch::factory()->create(['match_id' => '12345678']);

    expect(fn () => DotaMatch::factory()->create(['match_id' => '12345678']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

test('steam api service converts steam id to account id correctly', function () {
    $service = new SteamApiService;

    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('convertSteamIdToAccountId');
    $method->setAccessible(true);

    $accountId = $method->invoke($service, '76561198012345678');

    expect($accountId)->toBe(52079950);
});

test('fonnte service can be instantiated', function () {
    $service = new FonnteService;

    expect($service)->toBeInstanceOf(FonnteService::class);
});

test('match notification marks match as notified after successful send', function () {
    $member = Member::factory()->create([
        'steam_id' => '76561198052079951',
    ]);

    \App\Models\Hero::factory()->create([
        'hero_id' => 1,
        'name' => 'npc_dota_hero_antimage',
        'localized_name' => 'Anti-Mage',
    ]);

    \App\Models\Setting::updateOrCreate(
        ['key' => 'fonnte_phone_number'],
        ['value' => '628123456789', 'type' => 'string', 'label' => 'Fonnte Phone Number']
    );

    $match = DotaMatch::factory()->create([
        'members' => [$member->id],
        'match_data' => [
            'radiant_win' => true,
            'game_mode' => 1,
            'duration' => 1800,
            'radiant_score' => 25,
            'dire_score' => 20,
            'players' => [
                [
                    'account_id' => 91814223, // Matches steam_id 76561198052079951
                    'player_slot' => 0,
                    'hero_id' => 1,
                    'kills' => 10,
                    'deaths' => 3,
                    'assists' => 15,
                ],
            ],
        ],
        'notified_at' => null,
    ]);

    mock(FonnteService::class)
        ->shouldReceive('sendMessage')
        ->once()
        ->andReturn(true);

    mock(\App\Services\TelegramService::class)
        ->shouldReceive('sendMessage')
        ->andReturn(true);

    $job = new \App\Jobs\ProcessMatchNotification($match);
    $job->handle(app(FonnteService::class), app(\App\Services\TelegramService::class), app(\App\Services\HeroService::class));

    expect($match->fresh()->notified_at)->not->toBeNull();
});

test('match notification includes game mode, duration, scores and opendota link', function () {
    $member = Member::factory()->create([
        'steam_id' => '76561198052079950',
        'name' => 'TestPlayer',
    ]);

    \App\Models\Hero::factory()->create([
        'hero_id' => 5,
        'name' => 'npc_dota_hero_crystal_maiden',
        'localized_name' => 'Crystal Maiden',
    ]);

    \App\Models\Setting::updateOrCreate(
        ['key' => 'fonnte_phone_number'],
        ['value' => '628123456789', 'type' => 'string', 'label' => 'Fonnte Phone Number']
    );

    $match = DotaMatch::factory()->create([
        'match_id' => '8165933418',
        'members' => [$member->id],
        'match_data' => [
            'radiant_win' => true,
            'game_mode' => 23, // Turbo
            'duration' => 1440, // 24 minutes
            'radiant_score' => 28,
            'dire_score' => 17,
            'players' => [
                [
                    'account_id' => 91814222, // Matches steam_id 76561198052079950
                    'player_slot' => 0, // Radiant
                    'hero_id' => 5,
                    'kills' => 10,
                    'deaths' => 3,
                    'assists' => 15,
                ],
            ],
        ],
        'notified_at' => null,
    ]);

    $sentMessage = null;
    mock(FonnteService::class)
        ->shouldReceive('sendMessage')
        ->once()
        ->withArgs(function ($phone, $message) use (&$sentMessage) {
            $sentMessage = $message;

            return true;
        })
        ->andReturn(true);

    mock(\App\Services\TelegramService::class)
        ->shouldReceive('sendMessage')
        ->andReturn(true);

    $job = new \App\Jobs\ProcessMatchNotification($match);
    $job->handle(app(FonnteService::class), app(\App\Services\TelegramService::class), app(\App\Services\HeroService::class));

    // Job formats message with Telegram Markdown (**bold**) which is captured before FonnteService conversion
    expect($sentMessage)
        ->toContain('🕹️ **Turbo** _(24 minutes)_')
        ->toContain('**TestPlayer** won a game as Radiant')
        ->toContain('**Radiant** 28 ⚔️ 17 **Dire**')
        ->toContain('**TestPlayer** _(Crystal Maiden)_ - 10/3/15')
        ->toContain('> Match Detail opendota.com/matches/8165933418');
});

// ---------------------------------------------------------------------------
// Reminder tests
// ---------------------------------------------------------------------------

/**
 * Helper: build a minimal match + member that can be dispatched through ProcessMatchNotification.
 * Returns [$dotaMatch, $member].
 */
function makeMatchWithMember(string $steamId, string $name, array $matchDataOverrides = []): array
{
    $member = Member::factory()->create([
        'steam_id' => $steamId,
        'name' => $name,
        'destination' => Destination::CODE_WHATSAPP,
    ]);

    // account_id = steamId - 76561197960265728
    $accountId = (int) ($steamId - 76561197960265728);

    \App\Models\Hero::firstOrCreate(
        ['hero_id' => 1],
        ['name' => 'npc_dota_hero_antimage', 'localized_name' => 'Anti-Mage'],
    );

    \App\Models\Setting::updateOrCreate(
        ['key' => 'fonnte_phone_number'],
        ['value' => '628111000000', 'type' => 'string', 'label' => 'Fonnte Phone Number'],
    );

    $defaultMatchData = [
        'radiant_win' => true,
        'game_mode' => 1,
        'duration' => 1800,
        'radiant_score' => 20,
        'dire_score' => 15,
        'players' => [
            [
                'account_id' => $accountId,
                'player_slot' => 0,
                'hero_id' => 1,
                'kills' => 5,
                'deaths' => 2,
                'assists' => 8,
            ],
        ],
    ];

    $dotaMatch = DotaMatch::factory()->create([
        'members' => [$member->id],
        'match_data' => array_merge($defaultMatchData, $matchDataOverrides),
        'match_timestamp' => today()->setTime(10, 0), // fixed daytime (UTC) — safely before 22:00 Jakarta
        'notified_at' => null,
    ]);

    return [$dotaMatch, $member];
}

function upsertDestinationTarget(string $code, string $target): void
{
    Destination::query()->updateOrCreate(
        ['code' => $code],
        [
            'name' => ucfirst($code),
            'target' => $target,
        ],
    );
}

test('reminder is sent when todays match count exactly equals max_matches', function () {
    $steamId = '76561198256821667';
    $name = 'ReminderPlayer';

    upsertDestinationTarget(Destination::CODE_WHATSAPP, '6285123456789');
    Reminder::factory()->create([
        'steam_id' => $steamId,
        'max_matches' => 2,
    ]);

    [$dotaMatch, $member] = makeMatchWithMember($steamId, $name);

    // Create one earlier match today so this new match is the 2nd (max_matches)
    DotaMatch::factory()->create([
        'members' => [$member->id],
        'match_timestamp' => today()->addHours(1),
    ]);

    // The match being processed is already created as match_timestamp=now() → total today = 2

    $reminderMessageSent = null;

    mock(FonnteService::class)
        ->shouldReceive('sendMessage')
        ->twice() // once for group notification, once for reminder
        ->withArgs(function ($phone, $message) use (&$reminderMessageSent) {
            if (str_contains($message, 'jaga kewarasan kamu')) {
                $reminderMessageSent = $message;
            }

            return true;
        })
        ->andReturn(true);

    mock(TelegramService::class)->shouldReceive('sendMessage')->andReturn(true);

    $job = new ProcessMatchNotification($dotaMatch);
    $job->handle(app(FonnteService::class), app(TelegramService::class), app(HeroService::class));

    expect($reminderMessageSent)->toContain($name)
        ->and($reminderMessageSent)->toContain('2');
});

test('reminder is not sent when todays match count is below max_matches', function () {
    $steamId = '76561198256821668';

    upsertDestinationTarget(Destination::CODE_WHATSAPP, '6285123456789');
    Reminder::factory()->create([
        'steam_id' => $steamId,
        'max_matches' => 3,
    ]);

    // Only 1 match today (below threshold of 3)
    [$dotaMatch] = makeMatchWithMember($steamId, 'PlayerBelow');

    $reminderCalled = false;

    mock(FonnteService::class)
        ->shouldReceive('sendMessage')
        ->withArgs(function ($phone, $message) use (&$reminderCalled) {
            if (str_contains($message, 'jaga kewarasan kamu')) {
                $reminderCalled = true;
            }

            return true;
        })
        ->andReturn(true);

    mock(TelegramService::class)->shouldReceive('sendMessage')->andReturn(true);

    $job = new ProcessMatchNotification($dotaMatch);
    $job->handle(app(FonnteService::class), app(TelegramService::class), app(HeroService::class));

    expect($reminderCalled)->toBeFalse();
});

test('reminder is not sent when todays match count exceeds max_matches', function () {
    $steamId = '76561198256821669';

    upsertDestinationTarget(Destination::CODE_WHATSAPP, '6285123456789');
    Reminder::factory()->create([
        'steam_id' => $steamId,
        'max_matches' => 2,
    ]);

    [$dotaMatch, $member] = makeMatchWithMember($steamId, 'PlayerAbove');

    // Create 2 extra matches today so total is 3 (above max_matches of 2)
    DotaMatch::factory()->count(2)->create([
        'members' => [$member->id],
        'match_timestamp' => today()->addHours(1),
    ]);

    $reminderCalled = false;

    mock(FonnteService::class)
        ->shouldReceive('sendMessage')
        ->withArgs(function ($phone, $message) use (&$reminderCalled) {
            if (str_contains($message, 'jaga kewarasan kamu')) {
                $reminderCalled = true;
            }

            return true;
        })
        ->andReturn(true);

    mock(TelegramService::class)->shouldReceive('sendMessage')->andReturn(true);

    $job = new ProcessMatchNotification($dotaMatch);
    $job->handle(app(FonnteService::class), app(TelegramService::class), app(HeroService::class));

    expect($reminderCalled)->toBeFalse();
});

test('reminder is not sent for members without a reminders config entry', function () {
    $steamId = '76561198256821670';

    [$dotaMatch] = makeMatchWithMember($steamId, 'PlayerNoConfig');

    $reminderCalled = false;

    mock(FonnteService::class)
        ->shouldReceive('sendMessage')
        ->withArgs(function ($phone, $message) use (&$reminderCalled) {
            if (str_contains($message, 'jaga kewarasan kamu')) {
                $reminderCalled = true;
            }

            return true;
        })
        ->andReturn(true);

    mock(TelegramService::class)->shouldReceive('sendMessage')->andReturn(true);

    $job = new ProcessMatchNotification($dotaMatch);
    $job->handle(app(FonnteService::class), app(TelegramService::class), app(HeroService::class));

    expect($reminderCalled)->toBeFalse();
});

test('reminder is sent via telegram when telegram destination is configured', function () {
    $steamId = '76561198256821671';
    $name = 'TelegramPlayer';
    $chatId = '-1001234567890';

    upsertDestinationTarget(Destination::CODE_TELEGRAM, $chatId);
    Reminder::factory()->create([
        'steam_id' => $steamId,
        'max_matches' => 1,
    ]);

    [$dotaMatch, $member] = makeMatchWithMember($steamId, $name);
    $member->update([
        'destination' => Destination::CODE_TELEGRAM,
    ]);

    $telegramReminderSent = false;

    mock(FonnteService::class)->shouldReceive('sendMessage')->andReturn(true);

    mock(TelegramService::class)
        ->shouldReceive('sendMessage')
        ->withArgs(function (...$args) use ($name, $chatId, &$telegramReminderSent) {
            if (count($args) === 3) {
                [$message, $token, $receivedChatId] = $args;

                if ($token === 'ai' && $receivedChatId === $chatId && str_contains($message, $name)) {
                    $telegramReminderSent = true;
                }
            }

            return true;
        })
        ->andReturn(true);

    $job = new ProcessMatchNotification($dotaMatch);
    $job->handle(app(FonnteService::class), app(TelegramService::class), app(HeroService::class));

    expect($telegramReminderSent)->toBeTrue();
});

test('reminder is sent via both whatsapp and telegram when both destinations are configured', function () {
    $steamId = '76561198256821672';
    $name = 'BothPlayer';
    $chatId = '-1009876543210';

    upsertDestinationTarget(Destination::CODE_WHATSAPP, '6285123456789');
    upsertDestinationTarget(Destination::CODE_TELEGRAM, $chatId);
    Reminder::factory()->create([
        'steam_id' => $steamId,
        'max_matches' => 1,
    ]);

    [$dotaMatch] = makeMatchWithMember($steamId, $name);
    Member::factory()->create([
        'steam_id' => $steamId,
        'name' => $name,
        'destination' => Destination::CODE_TELEGRAM,
    ]);

    $whatsappReminderSent = false;
    $telegramReminderSent = false;

    mock(FonnteService::class)
        ->shouldReceive('sendMessage')
        ->withArgs(function ($phone, $message) use ($name, &$whatsappReminderSent) {
            if (str_contains($message, 'jaga kewarasan kamu') && str_contains($message, $name)) {
                $whatsappReminderSent = true;
            }

            return true;
        })
        ->andReturn(true);

    mock(TelegramService::class)
        ->shouldReceive('sendMessage')
        ->withArgs(function (...$args) use ($name, $chatId, &$telegramReminderSent) {
            if (count($args) === 3) {
                [$message, $token, $receivedChatId] = $args;

                if ($token === 'ai' && $receivedChatId === $chatId && str_contains($message, $name)) {
                    $telegramReminderSent = true;
                }
            }

            return true;
        })
        ->andReturn(true);

    $job = new ProcessMatchNotification($dotaMatch);
    $job->handle(app(FonnteService::class), app(TelegramService::class), app(HeroService::class));

    expect($whatsappReminderSent)->toBeTrue()
        ->and($telegramReminderSent)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Boundary breach (late-night) reminder tests
// ---------------------------------------------------------------------------

test('boundary breach reminder is sent when match starts at exactly 22:00 GMT+7', function () {
    $steamId = '76561198256821673';
    $name = 'NightOwl';

    upsertDestinationTarget(Destination::CODE_WHATSAPP, '6285700000001');
    Reminder::factory()->create([
        'steam_id' => $steamId,
        'max_matches' => 99,
    ]);

    [$dotaMatch, $member] = makeMatchWithMember($steamId, $name);

    $dotaMatch->update([
        'match_timestamp' => \Carbon\Carbon::now('Asia/Jakarta')->setTime(22, 0, 0),
    ]);

    $breachMessageSent = null;

    mock(FonnteService::class)
        ->shouldReceive('sendMessage')
        ->withArgs(function ($phone, $message) use (&$breachMessageSent) {
            if (str_contains($message, 'Boundary breach detected')) {
                $breachMessageSent = $message;
            }

            return true;
        })
        ->andReturn(true);

    mock(TelegramService::class)->shouldReceive('sendMessage')->andReturn(true);

    $job = new ProcessMatchNotification($dotaMatch);
    $job->handle(app(FonnteService::class), app(TelegramService::class), app(HeroService::class));

    expect($breachMessageSent)
        ->toContain($name)
        ->toContain('22:00')
        ->toContain('Boundary breach detected');
});

test('boundary breach reminder is sent when match starts at 23:30 GMT+7', function () {
    $steamId = '76561198256821674';
    $name = 'LateNightPlayer';

    upsertDestinationTarget(Destination::CODE_WHATSAPP, '6285700000002');
    Reminder::factory()->create([
        'steam_id' => $steamId,
        'max_matches' => 99,
    ]);

    [$dotaMatch] = makeMatchWithMember($steamId, $name);

    $dotaMatch->update([
        'match_timestamp' => \Carbon\Carbon::now('Asia/Jakarta')->setTime(23, 30, 0),
    ]);

    $breachSent = false;

    mock(FonnteService::class)
        ->shouldReceive('sendMessage')
        ->withArgs(function ($phone, $message) use (&$breachSent) {
            if (str_contains($message, 'Boundary breach detected')) {
                $breachSent = true;
            }

            return true;
        })
        ->andReturn(true);

    mock(TelegramService::class)->shouldReceive('sendMessage')->andReturn(true);

    $job = new ProcessMatchNotification($dotaMatch);
    $job->handle(app(FonnteService::class), app(TelegramService::class), app(HeroService::class));

    expect($breachSent)->toBeTrue();
});

test('boundary breach reminder is not sent when match starts at 21:59 GMT+7', function () {
    $steamId = '76561198256821675';

    upsertDestinationTarget(Destination::CODE_WHATSAPP, '6285700000003');
    Reminder::factory()->create([
        'steam_id' => $steamId,
        'max_matches' => 99,
    ]);

    [$dotaMatch] = makeMatchWithMember($steamId, 'EarlyPlayer');

    $dotaMatch->update([
        'match_timestamp' => \Carbon\Carbon::now('Asia/Jakarta')->setTime(21, 59, 0),
    ]);

    $breachSent = false;

    mock(FonnteService::class)
        ->shouldReceive('sendMessage')
        ->withArgs(function ($phone, $message) use (&$breachSent) {
            if (str_contains($message, 'Boundary breach detected')) {
                $breachSent = true;
            }

            return true;
        })
        ->andReturn(true);

    mock(TelegramService::class)->shouldReceive('sendMessage')->andReturn(true);

    $job = new ProcessMatchNotification($dotaMatch);
    $job->handle(app(FonnteService::class), app(TelegramService::class), app(HeroService::class));

    expect($breachSent)->toBeFalse();
});

test('boundary breach and max matches reminders both fire when both conditions are met', function () {
    $steamId = '76561198256821676';
    $name = 'NightGrinder';

    upsertDestinationTarget(Destination::CODE_WHATSAPP, '6285700000004');
    Reminder::factory()->create([
        'steam_id' => $steamId,
        'max_matches' => 1,
    ]);

    [$dotaMatch, $member] = makeMatchWithMember($steamId, $name);

    // match_timestamp at 22:30 Jakarta — triggers both checks (1 match today = max_matches)
    $dotaMatch->update([
        'match_timestamp' => \Carbon\Carbon::now('Asia/Jakarta')->setTime(22, 30, 0),
    ]);

    $messagesSentToPersonal = [];

    mock(FonnteService::class)
        ->shouldReceive('sendMessage')
        ->withArgs(function ($phone, $message) use (&$messagesSentToPersonal) {
            if (str_contains($message, 'jaga kewarasan kamu') || str_contains($message, 'Boundary breach detected')) {
                $messagesSentToPersonal[] = $message;
            }

            return true;
        })
        ->andReturn(true);

    mock(TelegramService::class)->shouldReceive('sendMessage')->andReturn(true);

    $job = new ProcessMatchNotification($dotaMatch);
    $job->handle(app(FonnteService::class), app(TelegramService::class), app(HeroService::class));

    // Both the max_matches reminder and the boundary breach should have been sent
    expect($messagesSentToPersonal)->toHaveCount(2)
        ->and($messagesSentToPersonal[0])->toContain('game DotA hari ini')
        ->and($messagesSentToPersonal[1])->toContain('Boundary breach detected');
});

// ---------------------------------------------------------------------------
// Chart / radar image tests
// ---------------------------------------------------------------------------

/**
 * Build a two-member WhatsApp match where both top-2 fantasy scores exceed 54
 * and the gap is within 5, on a winning team.
 */
function makeChartEligibleMatch(array $overrides = []): DotaMatch
{
    \App\Models\Hero::firstOrCreate(
        ['hero_id' => 11],
        ['name' => 'npc_dota_hero_nevermore', 'localized_name' => 'Shadow Fiend'],
    );
    \App\Models\Hero::firstOrCreate(
        ['hero_id' => 12],
        ['name' => 'npc_dota_hero_queenofpain', 'localized_name' => 'Queen of Pain'],
    );

    \App\Models\Setting::updateOrCreate(
        ['key' => 'fonnte_phone_number'],
        ['value' => '628111000000', 'type' => 'string', 'label' => 'Fonnte Phone Number'],
    );
    \App\Models\Setting::updateOrCreate(
        ['key' => 'telegram_group_id'],
        ['value' => '-100999888777', 'type' => 'string', 'label' => 'Telegram Group ID'],
    );

    $member1 = Member::factory()->create(['steam_id' => '76561198300000001', 'name' => 'Kudog']);
    $member2 = Member::factory()->create(['steam_id' => '76561198300000002', 'name' => 'Kocrol']);

    // High-performing players: both get scores > 54 with a small gap
    $defaultMatchData = [
        'radiant_win' => true,
        'game_mode' => 23,
        'duration' => 1600,
        'radiant_score' => 30,
        'dire_score' => 10,
        'players' => [
            [
                'account_id' => (int) ('76561198300000001' - 76561197960265728),
                'player_slot' => 0, // Radiant
                'hero_id' => 11,
                'kills' => 14,
                'deaths' => 1,
                'assists' => 12,
                'hero_damage' => 28000,
                'tower_damage' => 3000,
                'hero_healing' => 0,
                'net_worth' => 18000,
                'gold_per_min' => 680,
                'xp_per_min' => 780,
                'last_hits' => 160,
                'level' => 25,
                'benchmarks' => ['gold_per_min' => ['pct' => 0.90], 'xp_per_min' => ['pct' => 0.88]],
            ],
            [
                'account_id' => (int) ('76561198300000002' - 76561197960265728),
                'player_slot' => 1, // Radiant
                'hero_id' => 12,
                'kills' => 12,
                'deaths' => 2,
                'assists' => 14,
                'hero_damage' => 25000,
                'tower_damage' => 2500,
                'hero_healing' => 0,
                'net_worth' => 16000,
                'gold_per_min' => 640,
                'xp_per_min' => 750,
                'last_hits' => 140,
                'level' => 24,
                'benchmarks' => ['gold_per_min' => ['pct' => 0.85], 'xp_per_min' => ['pct' => 0.83]],
            ],
        ],
    ];

    return DotaMatch::factory()->create([
        'members' => [$member1->id, $member2->id],
        'match_data' => array_merge($defaultMatchData, $overrides),
        'match_timestamp' => now(),
        'notified_at' => null,
    ]);
}

test('sendImage is called instead of sendMessage when chart conditions are met for whatsapp', function () {
    $dotaMatch = makeChartEligibleMatch();

    $imageUsed = false;

    mock(FonnteService::class)
        ->shouldReceive('sendMessage')->never()
        ->shouldReceive('sendImage')
        ->once()
        ->withArgs(function ($phone, $path, $message) use (&$imageUsed) {
            $imageUsed = file_exists($path) || str_ends_with($path, '.png');

            return true;
        })
        ->andReturn(true);

    mock(TelegramService::class)
        ->shouldReceive('sendMessage')->andReturn(true)
        ->shouldReceive('sendPhoto')->andReturn(true);

    $job = new ProcessMatchNotification($dotaMatch);
    $job->handle(app(FonnteService::class), app(TelegramService::class), app(\App\Services\HeroService::class));

    expect($dotaMatch->fresh()->notified_at)->not->toBeNull();
});

test('sendMessage is used and sendImage is not called when match is a loss', function () {
    $dotaMatch = makeChartEligibleMatch(['radiant_win' => false]);

    mock(FonnteService::class)
        ->shouldReceive('sendMessage')->once()->andReturn(true)
        ->shouldReceive('sendImage')->never();

    mock(TelegramService::class)
        ->shouldReceive('sendMessage')->andReturn(true)
        ->shouldReceive('sendPhoto')->never();

    $job = new ProcessMatchNotification($dotaMatch);
    $job->handle(app(FonnteService::class), app(TelegramService::class), app(\App\Services\HeroService::class));
});

test('sendMessage is used when only one member is in the match', function () {
    \App\Models\Hero::firstOrCreate(
        ['hero_id' => 1],
        ['name' => 'npc_dota_hero_antimage', 'localized_name' => 'Anti-Mage'],
    );
    \App\Models\Setting::updateOrCreate(
        ['key' => 'fonnte_phone_number'],
        ['value' => '628111000000', 'type' => 'string', 'label' => 'Fonnte Phone Number'],
    );

    $member = Member::factory()->create(['steam_id' => '76561198300000010', 'name' => 'Solo']);

    $dotaMatch = DotaMatch::factory()->create([
        'members' => [$member->id],
        'match_data' => [
            'radiant_win' => true,
            'game_mode' => 23,
            'duration' => 1600,
            'radiant_score' => 30,
            'dire_score' => 10,
            'players' => [[
                'account_id' => (int) ('76561198300000010' - 76561197960265728),
                'player_slot' => 0,
                'hero_id' => 1,
                'kills' => 14, 'deaths' => 1, 'assists' => 12,
                'hero_damage' => 28000, 'tower_damage' => 3000, 'hero_healing' => 0,
                'net_worth' => 18000, 'gold_per_min' => 680, 'xp_per_min' => 780,
                'last_hits' => 160, 'level' => 25, 'benchmarks' => [],
            ]],
        ],
        'notified_at' => null,
    ]);

    mock(FonnteService::class)
        ->shouldReceive('sendMessage')->once()->andReturn(true)
        ->shouldReceive('sendImage')->never();

    mock(TelegramService::class)
        ->shouldReceive('sendMessage')->andReturn(true)
        ->shouldReceive('sendPhoto')->never();

    $job = new ProcessMatchNotification($dotaMatch);
    $job->handle(app(FonnteService::class), app(TelegramService::class), app(\App\Services\HeroService::class));
});
