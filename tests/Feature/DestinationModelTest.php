<?php

use App\Models\Destination;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('default destinations are created by migration', function () {
    $codes = Destination::query()->pluck('code')->all();

    expect($codes)
        ->toContain(Destination::CODE_WHATSAPP)
        ->toContain(Destination::CODE_TELEGRAM);
});

test('member destination can resolve destination config by code', function () {
    $member = \App\Models\Member::factory()->create([
        'destination' => Destination::CODE_WHATSAPP,
    ]);

    $destination = $member->destinationConfig()->first();

    expect($destination)->not->toBeNull();
    expect($destination?->code)->toBe(Destination::CODE_WHATSAPP);
});
