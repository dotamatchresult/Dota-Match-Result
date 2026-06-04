<?php

use App\Jobs\EvaluateChallengesJob;
use App\Models\DotaMatch;
use App\Services\DailyChallenge\ChallengeProgressService;

use function Pest\Laravel\mock;

// --- Job Delegates to Service ---

test('job delegates to ChallengeProgressService', function () {
    $dotaMatch = DotaMatch::factory()->make(['id' => 999]);

    $mock = mock(ChallengeProgressService::class);
    $mock->shouldReceive('processMatch')
        ->once()
        ->with(\Mockery::on(fn (DotaMatch $m) => $m->id === $dotaMatch->id));

    app()->instance(ChallengeProgressService::class, $mock);

    $job = new EvaluateChallengesJob($dotaMatch);
    $job->handle($mock);
});
