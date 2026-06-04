<?php

namespace App\Console\Commands;

use App\Models\Destination;
use App\Services\DailyChallenge\ChallengeAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AssignDailyChallenges extends Command
{
    protected $signature = 'challenges:assign-daily';

    protected $description = 'Assign daily challenges to all destinations';

    public function handle(): int
    {
        if (! config('dota.daily_challenge.enabled')) {
            $this->warn('Daily challenge assignment is disabled in configuration.');

            Log::info('Daily challenge assignment skipped: disabled in config');

            return self::SUCCESS;
        }

        $this->info('Starting daily challenge assignment...');

        $timezone = config('dota.daily_challenge.timezone', 'Asia/Jakarta');
        $today = now()->setTimezone($timezone)->toDateString();

        Log::info('Daily challenge assignment started', [
            'date' => $today,
            'timezone' => $timezone,
        ]);

        $assigned = 0;
        $skippedLimit = 0;
        $skippedIdempotency = 0;
        $skippedNoChallenge = 0;
        $errors = 0;

        /** @var ChallengeAssignmentService $service */
        $service = app(ChallengeAssignmentService::class);

        Destination::query()->chunkById(100, function ($destinations) use (
            $service,
            $today,
            &$assigned,
            &$skippedLimit,
            &$skippedIdempotency,
            &$skippedNoChallenge,
            &$errors
        ) {
            foreach ($destinations as $destination) {
                try {
                    // Pre-check: count active challenges to distinguish skip reasons
                    $activeCount = $destination->destinationChallenges()
                        ->where('status', 'active')
                        ->count();

                    $maxActive = (int) config('dota.daily_challenge.max_active_per_destination', 5);

                    $existingToday = $destination->destinationChallenges()
                        ->whereDate('assigned_date', $today)
                        ->exists();

                    $result = $service->assignForDestination($destination);

                    if ($result) {
                        $assigned++;
                    } elseif ($existingToday) {
                        $skippedIdempotency++;
                    } elseif ($activeCount >= $maxActive) {
                        $skippedLimit++;
                    } else {
                        $skippedNoChallenge++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    Log::error('Daily challenge assignment failed for destination', [
                        'destination_id' => $destination->id,
                        'destination_code' => $destination->code,
                        'error' => $e->getMessage(),
                    ]);

                    $this->error("Failed for destination {$destination->id} ({$destination->code}): {$e->getMessage()}");
                }
            }
        });

        $this->info('Daily challenge assignment completed.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Assigned', $assigned],
                ['Skipped (already assigned today)', $skippedIdempotency],
                ['Skipped (max active limit)', $skippedLimit],
                ['Skipped (no challenges available)', $skippedNoChallenge],
                ['Errors', $errors],
            ]
        );

        Log::info('Daily challenge assignment completed', [
            'date' => $today,
            'assigned' => $assigned,
            'skipped_idempotency' => $skippedIdempotency,
            'skipped_limit' => $skippedLimit,
            'skipped_no_challenge' => $skippedNoChallenge,
            'errors' => $errors,
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
