<?php

use App\Models\Challenge;
use App\Models\ChallengeNotification;
use App\Models\DestinationChallenge;
use App\Models\Hero;
use App\Services\DailyChallenge\ChallengeMessageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Hero::query()->firstOrCreate(
        ['hero_id' => 14],
        ['name' => 'npc_dota_hero_pudge', 'localized_name' => 'Pudge']
    );
});

// --- assigned_announcement ---

test('assigned announcement renders correct format', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'hero_win',
        'configuration' => ['hero_id' => 14],
    ]);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'challenge_id' => $challenge->id,
        'current_requirement' => 2,
        'current_progress' => 0,
        'progress_data' => null,
    ]);

    $notification = ChallengeNotification::factory()->create([
        'destination_challenge_id' => $destinationChallenge->id,
        'type' => 'assigned_announcement',
        'status' => 'pending',
    ]);

    // Load relationship
    $notification->load('destinationChallenge.challenge');

    $renderer = app(ChallengeMessageRenderer::class);
    $message = $renderer->render($notification);

    expect($message)->toContain('🎯 TANTANGAN HARIAN');
    expect($message)->toContain('Menangkan 2 pertandingan menggunakan Pudge');
    expect($message)->toContain('0 / 2');
    expect($message)->toContain('Semoga beruntung');
});

// --- backlog_full ---

test('backlog full renders correct format', function () {
    $notification = ChallengeNotification::factory()->create([
        'destination_challenge_id' => null,
        'type' => 'backlog_full',
        'status' => 'pending',
        'payload' => ['destination_id' => 1],
    ]);

    $renderer = app(ChallengeMessageRenderer::class);
    $message = $renderer->render($notification);

    $max = config('dota.daily_challenge.max_active_per_destination', 5);

    expect($message)->toContain('📚 TANTANGAN MENUMPUK');
    expect($message)->toContain("Kamu sudah punya {$max} tantangan aktif");
    expect($message)->toContain('Selesaikan dulu yang ada');
});

// --- single completion ---

test('single completion renders correct format', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'hero_win',
        'configuration' => ['hero_id' => 14],
    ]);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'challenge_id' => $challenge->id,
        'current_requirement' => 2,
        'current_progress' => 2,
        'progress_data' => null,
    ]);

    $notification = ChallengeNotification::factory()->create([
        'destination_challenge_id' => $destinationChallenge->id,
        'type' => 'completed',
        'status' => 'pending',
    ]);

    $notification->load('destinationChallenge.challenge');

    $renderer = app(ChallengeMessageRenderer::class);
    $message = $renderer->render($notification);

    expect($message)->toContain('🎉 TANTANGAN SELESAI');
    expect($message)->toContain('✅ Menangkan 2 pertandingan menggunakan Pudge');
    expect($message)->toContain('Kerja bagus');
});

// --- multi-completion batch ---

test('multi completion batch renders correct format', function () {
    $challenges = [
        Challenge::factory()->create([
            'code' => 'hero_win',
            'configuration' => ['hero_id' => 14],
        ]),
        Challenge::factory()->create([
            'code' => 'total_kills',
        ]),
        Challenge::factory()->create([
            'code' => 'fast_win',
        ]),
    ];

    $destinationChallenges = collect($challenges)->map(function ($challenge, $i) {
        $req = [2, 30, 25][$i];

        return DestinationChallenge::factory()->create([
            'challenge_id' => $challenge->id,
            'current_requirement' => $req,
            'current_progress' => $req,
            'progress_data' => null,
        ]);
    });

    $notifications = $destinationChallenges->map(function ($dc) {
        $n = ChallengeNotification::factory()->create([
            'destination_challenge_id' => $dc->id,
            'type' => 'completed',
            'status' => 'pending',
        ]);
        $n->load('destinationChallenge.challenge');

        return $n;
    })->all();

    $renderer = app(ChallengeMessageRenderer::class);
    $message = $renderer->renderBatch($notifications);

    expect($message)->toContain('🎉 TANTANGAN SELESAI');
    expect($message)->toContain('Tim kamu menyelesaikan 3 tantangan');
    expect($message)->toContain('✅ Menangkan 2 pertandingan menggunakan Pudge');
    expect($message)->toContain('✅ Dapatkan 30 total kill');
    expect($message)->toContain('✅ Menangkan pertandingan dalam waktu kurang dari 25 menit');
    expect($message)->toContain('Teruskan');
});

// --- single item via renderBatch uses single format ---

test('renderBatch with one item uses single completion format', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'hero_win',
        'configuration' => ['hero_id' => 14],
    ]);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'challenge_id' => $challenge->id,
        'current_requirement' => 2,
        'current_progress' => 2,
        'progress_data' => null,
    ]);

    $notification = ChallengeNotification::factory()->create([
        'destination_challenge_id' => $destinationChallenge->id,
        'type' => 'completed',
        'status' => 'pending',
    ]);

    $notification->load('destinationChallenge.challenge');

    $renderer = app(ChallengeMessageRenderer::class);
    $message = $renderer->renderBatch([$notification]);

    expect($message)->toContain('🎉 TANTANGAN SELESAI');
    expect($message)->toContain('✅ Menangkan 2 pertandingan menggunakan Pudge');
    expect($message)->toContain('Kerja bagus');
    expect($message)->not->toContain('Tim kamu menyelesaikan');
});
