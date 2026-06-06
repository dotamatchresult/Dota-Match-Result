<?php

namespace App\Services\DailyChallenge;

use App\Models\ChallengeEvent;
use App\Models\ChallengeNotification;
use App\Models\Destination;
use App\Models\DestinationChallenge;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChallengeReviewService
{
    public function __construct(
        protected ChallengeDescriptionService $descriptionService,
        protected ChallengeReviewGuardService $guardService,
    ) {}

    /**
     * Run the end-of-day review for all active challenges across all destinations.
     *
     * For each destination, checks the guard for unresolved OpenDota matches.
     * If blocked, creates a review_delayed notification and skips the destination.
     * If forced (threshold exceeded), creates review_forced events then proceeds.
     *
     * For each incomplete challenge:
     * - Increments current_requirement for accumulative challenges (capped at max_requirement)
     * - Increments failed_days for all incomplete challenges
     * - Creates audit events (incremented, failed_review, review_forced)
     * - Creates one recap notification per destination with failures
     *
     * @return array{reviewed: int, incremented: int, failed: int, recap_destinations: int, deferred: int, forced: int}
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
        $deferred = 0;
        $forced = 0;

        // Count total active challenges for today (before idempotency filter)
        $reviewed = DestinationChallenge::query()
            ->where('status', 'active')
            ->whereDate('assigned_date', $today)
            ->count();

        // Load all eligible challenges grouped by destination
        $eligibleChallenges = DestinationChallenge::query()
            ->where('status', 'active')
            ->whereDate('assigned_date', $today)
            ->whereDoesntHave('events', function ($query) use ($today) {
                $query->whereIn('type', ['incremented', 'failed_review'])
                    ->whereDate('created_at', $today);
            })
            ->with(['challenge', 'destination'])
            ->get()
            ->groupBy('destination_id');

        /** @var array<int, array<int, array{dc: DestinationChallenge, completed: bool}>> $destinationResults */
        $destinationResults = [];

        foreach ($eligibleChallenges as $destinationId => $challenges) {
            /** @var DestinationChallenge $firstChallenge */
            $firstChallenge = $challenges->first();
            $destination = $firstChallenge->destination;

            if ($destination === null) {
                Log::warning('ChallengeReviewService: destination not found for challenge group', [
                    'destination_id' => $destinationId,
                ]);

                continue;
            }

            // Check guard for this destination
            $decision = $this->guardService->canReviewDestination($destination);

            if ($decision->blocked) {
                // Create review_delayed notification (dedup: one per destination per day)
                $this->createDelayNotification($destination, $decision->blockingMatches, $today);

                $deferred++;

                Log::info('ChallengeReviewService: review deferred for destination', [
                    'destination_id' => $destination->id,
                    'blocking_match_ids' => $decision->blockingMatches,
                ]);

                continue;
            }

            if ($decision->forceReview) {
                // Create review_forced events for each active challenge
                foreach ($challenges as $dc) {
                    ChallengeEvent::create([
                        'destination_challenge_id' => $dc->id,
                        'type' => 'review_forced',
                        'value_before' => $dc->current_progress,
                        'value_after' => $dc->current_requirement,
                        'payload' => [
                            'reason' => 'force_review',
                            'blocking_match_ids' => $decision->blockingMatches,
                        ],
                    ]);
                }

                $forced++;

                Log::info('ChallengeReviewService: force reviewing destination', [
                    'destination_id' => $destination->id,
                    'blocking_match_ids' => $decision->blockingMatches,
                    'challenge_count' => $challenges->count(),
                ]);
            }

            // Run the actual review for this destination
            $results = $this->reviewDestinationChallenges($challenges);

            $destinationResults[$destinationId] = $results['entries'];
            $incremented += $results['incremented'];
            $failed += $results['failed'];
        }

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
            'deferred' => $deferred,
            'forced' => $forced,
        ]);

        return [
            'reviewed' => $reviewed,
            'incremented' => $incremented,
            'failed' => $failed,
            'recap_destinations' => $recapDestinations,
            'deferred' => $deferred,
            'forced' => $forced,
        ];
    }

    /**
     * Review a single destination's challenges.
     *
     * Public method for reuse by the deferred review command.
     * Does NOT check the guard — the caller is responsible for gate decisions.
     *
     * @return array{reviewed: int, incremented: int, failed: int}
     */
    public function reviewDestination(Destination $destination): array
    {
        $timezone = config('dota.daily_challenge.timezone', 'Asia/Jakarta');
        $today = Carbon::now($timezone)->toDateString();

        // Load eligible challenges for this destination
        $challenges = DestinationChallenge::query()
            ->where('destination_id', $destination->id)
            ->where('status', 'active')
            ->whereDate('assigned_date', $today)
            ->whereDoesntHave('events', function ($query) use ($today) {
                $query->whereIn('type', ['incremented', 'failed_review'])
                    ->whereDate('created_at', $today);
            })
            ->with(['challenge', 'destination'])
            ->get();

        if ($challenges->isEmpty()) {
            return ['reviewed' => 0, 'incremented' => 0, 'failed' => 0];
        }

        $results = $this->reviewDestinationChallenges($challenges);

        // Check if recap is needed
        $failedChallenges = array_filter($results['entries'], fn (array $r) => ! $r['completed']);

        if (count($failedChallenges) > 0) {
            $failedChallengeData = array_map(function (array $r) {
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
                    'destination_id' => $destination->id,
                    'failed_count' => count($failedChallengeData),
                    'failed_challenges' => array_values($failedChallengeData),
                ],
            ]);

            Log::info('ChallengeReviewService: recap notification created (deferred review)', [
                'destination_id' => $destination->id,
                'failed_count' => count($failedChallengeData),
            ]);
        }

        Log::info('ChallengeReviewService: destination review completed', [
            'destination_id' => $destination->id,
            'reviewed' => $challenges->count(),
            'incremented' => $results['incremented'],
            'failed' => $results['failed'],
        ]);

        return [
            'reviewed' => $challenges->count(),
            'incremented' => $results['incremented'],
            'failed' => $results['failed'],
        ];
    }

    /**
     * Process a batch of challenges for a single destination.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, DestinationChallenge>  $challenges
     * @return array{entries: array<int, array{dc: DestinationChallenge, completed: bool}>, incremented: int, failed: int}
     */
    private function reviewDestinationChallenges($challenges): array
    {
        $incremented = 0;
        $failed = 0;
        $entries = [];

        foreach ($challenges as $dc) {
            /** @var DestinationChallenge $dc */
            DB::transaction(function () use ($dc, &$incremented, &$failed, &$entries) {
                $challenge = $dc->challenge;

                // Skip completed challenges
                if ($dc->current_progress >= $dc->current_requirement) {
                    $entries[] = [
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

                $entries[] = [
                    'dc' => $dc,
                    'completed' => false,
                ];
            });
        }

        return [
            'entries' => $entries,
            'incremented' => $incremented,
            'failed' => $failed,
        ];
    }

    /**
     * Create a review_delayed notification for a destination.
     *
     * Deduplication: only creates if one doesn't already exist for this
     * destination today (same type, same payload destination_id, same date).
     *
     * @param  array<int>  $blockingMatchIds
     */
    private function createDelayNotification(Destination $destination, array $blockingMatchIds, string $today): void
    {
        $exists = ChallengeNotification::query()
            ->where('type', 'review_delayed')
            ->where('status', 'pending')
            ->whereDate('created_at', $today)
            ->where('payload->destination_id', $destination->id)
            ->exists();

        if ($exists) {
            Log::info('ChallengeReviewService: delay notification already exists, skipping', [
                'destination_id' => $destination->id,
            ]);

            return;
        }

        ChallengeNotification::create([
            'destination_challenge_id' => null,
            'type' => 'review_delayed',
            'status' => 'pending',
            'payload' => [
                'destination_id' => $destination->id,
                'blocking_match_ids' => $blockingMatchIds,
            ],
        ]);

        Log::info('ChallengeReviewService: delay notification created', [
            'destination_id' => $destination->id,
            'blocking_match_ids' => $blockingMatchIds,
        ]);
    }
}
