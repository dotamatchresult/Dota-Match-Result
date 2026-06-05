<?php

namespace App\Services\DailyChallenge;

use App\Models\ChallengeEvent;
use App\Models\ChallengeNotification;
use App\Models\DestinationChallenge;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChallengeReviewService
{
    public function __construct(
        protected ChallengeDescriptionService $descriptionService
    ) {}

    /**
     * Run the end-of-day review for all active challenges across all destinations.
     *
     * For each incomplete challenge:
     * - Increments current_requirement for accumulative challenges (capped at max_requirement)
     * - Increments failed_days for all incomplete challenges
     * - Creates audit events (incremented, failed_review)
     * - Creates one recap notification per destination with failures
     *
     * @return array{reviewed: int, incremented: int, failed: int, recap_destinations: int}
     */
    public function review(): array
    {
        $timezone = config('dota.daily_challenge.timezone', 'Asia/Jakarta');
        $today = Carbon::now($timezone)->toDateString();

        Log::info('ChallengeReviewService: starting end-of-day review', [
            'date' => $today,
            'timezone' => $timezone,
        ]);

        $incremented = 0;
        $failed = 0;
        $recapDestinations = 0;

        /** @var array<int, array<int, array{dc: DestinationChallenge, completed: bool}>> $destinationResults */
        $destinationResults = [];

        // Count total active challenges for today (before idempotency filter)
        $reviewed = DestinationChallenge::query()
            ->where('status', 'active')
            ->whereDate('assigned_date', $today)
            ->count();

        // Query active challenges, excluding already-reviewed ones (idempotency)
        DestinationChallenge::query()
            ->where('status', 'active')
            ->whereDate('assigned_date', $today)
            ->whereDoesntHave('events', function ($query) use ($today) {
                $query->whereIn('type', ['incremented', 'failed_review'])
                    ->whereDate('created_at', $today);
            })
            ->with(['challenge', 'destination'])
            ->chunkById(100, function ($destinationChallenges) use (&$incremented, &$failed, &$destinationResults) {
                foreach ($destinationChallenges as $dc) {
                    /** @var DestinationChallenge $dc */
                    DB::transaction(function () use ($dc, &$incremented, &$failed, &$destinationResults) {
                        $challenge = $dc->challenge;
                        $destinationId = $dc->destination_id;

                        // Skip completed challenges
                        if ($dc->current_progress >= $dc->current_requirement) {
                            $destinationResults[$destinationId][] = [
                                'dc' => $dc,
                                'completed' => true,
                            ];

                            return;
                        }

                        // Increment requirement for accumulative challenges
                        $oldRequirement = $dc->current_requirement;

                        if ($challenge->increment_value > 0 && $dc->current_requirement < $challenge->max_requirement) {
                            $newRequirement = min(
                                $dc->current_requirement + $challenge->increment_value,
                                $challenge->max_requirement
                            );

                            $dc->current_requirement = $newRequirement;

                            ChallengeEvent::create([
                                'destination_challenge_id' => $dc->id,
                                'type' => 'incremented',
                                'value_before' => $oldRequirement,
                                'value_after' => $newRequirement,
                            ]);

                            $incremented++;
                        }

                        // Increment failed_days for all incomplete challenges
                        $dc->failed_days += 1;

                        // Create failed_review event
                        ChallengeEvent::create([
                            'destination_challenge_id' => $dc->id,
                            'type' => 'failed_review',
                            'value_before' => $dc->current_progress,
                            'value_after' => $dc->current_requirement,
                        ]);

                        $dc->save();

                        $failed++;

                        $destinationResults[$destinationId][] = [
                            'dc' => $dc,
                            'completed' => false,
                        ];
                    });
                }
            });

        // Create recap notifications per destination (only if failures exist)
        foreach ($destinationResults as $destinationId => $results) {
            $failedChallenges = array_filter($results, fn (array $r) => ! $r['completed']);

            if (count($failedChallenges) === 0) {
                continue;
            }

            $failedChallengeData = array_map(function (array $r) {
                /** @var DestinationChallenge $dc */
                $dc = $r['dc'];

                return [
                    'description' => $this->descriptionService->describe($dc),
                    'progress' => $dc->current_progress,
                    'requirement' => $dc->current_requirement,
                ];
            }, $failedChallenges);

            ChallengeNotification::create([
                'destination_challenge_id' => null,
                'type' => 'recap',
                'status' => 'pending',
                'payload' => [
                    'destination_id' => $destinationId,
                    'failed_count' => count($failedChallengeData),
                    'failed_challenges' => array_values($failedChallengeData),
                ],
            ]);

            $recapDestinations++;

            Log::info('ChallengeReviewService: recap notification created', [
                'destination_id' => $destinationId,
                'failed_count' => count($failedChallengeData),
            ]);
        }

        Log::info('ChallengeReviewService: review completed', [
            'reviewed' => $reviewed,
            'incremented' => $incremented,
            'failed' => $failed,
            'recap_destinations' => $recapDestinations,
        ]);

        return [
            'reviewed' => $reviewed,
            'incremented' => $incremented,
            'failed' => $failed,
            'recap_destinations' => $recapDestinations,
        ];
    }
}
