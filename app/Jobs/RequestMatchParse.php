<?php

namespace App\Jobs;

use App\Models\DotaMatch;
use App\Services\OpenDotaParseService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RequestMatchParse implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public DotaMatch $dotaMatch
    ) {
        // Delay by 60 seconds to ensure OpenDota has received match data
        $this->delay(now()->addSeconds(60));
    }

    /**
     * Execute the job.
     */
    public function handle(OpenDotaParseService $parseService): void
    {
        // Request parse from OpenDota
        $result = $parseService->requestParse($this->dotaMatch->match_id);

        if (! $result) {
            Log::warning('Failed to request parse for match', [
                'match_id' => $this->dotaMatch->match_id,
            ]);

            $this->dotaMatch->update([
                'parse_status' => 'failed',
            ]);

            return;
        }

        // Update match with parse job information
        $this->dotaMatch->update([
            'parse_status' => 'parsing',
            'parse_job_id' => $result['job']['jobId'] ?? null,
            'parse_requested_at' => now(),
        ]);

        Log::info('Parse requested for match', [
            'match_id' => $this->dotaMatch->match_id,
            'job_id' => $this->dotaMatch->parse_job_id,
        ]);
    }

    /**
     * Get the number of times the job should be attempted.
     */
    public function tries(): int
    {
        return 3;
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [30, 120, 300]; // 30s, 2min, 5min
    }
}
