<?php

namespace App\Console\Commands;

use App\Services\DailyChallenge\ChallengeReviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReviewDailyChallenges extends Command
{
    protected $signature = 'challenges:review-daily';

    protected $description = 'Run end-of-day review for daily challenges';

    public function handle(ChallengeReviewService $service): int
    {
        $result = $service->review();

        $this->info("Reviewed: {$result['reviewed']}");
        $this->info("Incremented: {$result['incremented']}");
        $this->info("Failed: {$result['failed']}");
        $this->info("Recap destinations: {$result['recap_destinations']}");
        $this->info("Deferred: {$result['deferred']}");
        $this->info("Forced: {$result['forced']}");

        Log::info('ReviewDailyChallenges command completed', $result);

        return self::SUCCESS;
    }
}
