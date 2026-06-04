<?php

namespace App\Jobs;

use App\Models\DotaMatch;
use App\Services\DailyChallenge\ChallengeProgressService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EvaluateChallengesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(public DotaMatch $dotaMatch)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(ChallengeProgressService $service): void
    {
        $service->processMatch($this->dotaMatch);
    }
}
