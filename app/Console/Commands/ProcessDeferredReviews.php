<?php

namespace App\Console\Commands;

use App\Models\ChallengeEvent;
use App\Models\ChallengeNotification;
use App\Models\Destination;
use App\Services\DailyChallenge\ChallengeReviewGuardService;
use App\Services\DailyChallenge\ChallengeReviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessDeferredReviews extends Command
{
    protected $signature = 'challenges:process-deferred-reviews';

    protected $description = 'Process deferred daily challenge reviews after OpenDota delays are resolved';

    public function handle(
        ChallengeReviewGuardService $guardService,
        ChallengeReviewService $reviewService,
    ): int {
        $now = now();
        $timezone = config('dota.daily_challenge.timezone', 'Asia/Jakarta');
        $today = $now->copy()->setTimezone($timezone)->toDateString();

        // Find all pending review_delayed notifications created today
        $delayNotifications = ChallengeNotification::query()
            ->where('type', 'review_delayed')
            ->where('status', 'pending')
            ->whereDate('created_at', $today)
            ->get();

        if ($delayNotifications->isEmpty()) {
            $this->info('No deferred reviews to process.');

            return self::SUCCESS;
        }

        $processed = 0;
        $resolved = 0;
        $forced = 0;
        $stillBlocked = 0;

        // Group by destination (one notification per destination)
        foreach ($delayNotifications as $notification) {
            $destinationId = $notification->payload['destination_id'] ?? null;

            if (! $destinationId) {
                Log::warning('ProcessDeferredReviews: notification missing destination_id', [
                    'notification_id' => $notification->id,
                ]);
                $notification->update(['status' => 'sent']);

                continue;
            }

            $destination = Destination::find($destinationId);

            if (! $destination) {
                Log::warning('ProcessDeferredReviews: destination not found', [
                    'destination_id' => $destinationId,
                    'notification_id' => $notification->id,
                ]);
                $notification->update(['status' => 'sent']);

                continue;
            }

            // Check if blocking matches have been resolved
            $decision = $guardService->canReviewDestination($destination);

            if ($decision->blocked) {
                $stillBlocked++;
                $this->line("Still blocked: destination {$destination->id} ({$destination->code})");

                continue;
            }

            // Review can now proceed (either allowed or force)
            if ($decision->forceReview) {
                // Load active challenges for this destination to create review_forced events
                $challenges = \App\Models\DestinationChallenge::query()
                    ->where('destination_id', $destination->id)
                    ->where('status', 'active')
                    ->whereDate('assigned_date', $today)
                    ->get();

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

                Log::info('ProcessDeferredReviews: force reviewing destination', [
                    'destination_id' => $destination->id,
                    'blocking_match_ids' => $decision->blockingMatches,
                ]);
            }

            // Run the review for this destination
            $result = $reviewService->reviewDestination($destination);

            // Mark notification as sent
            $notification->update(['status' => 'sent']);

            $resolved++;
            $processed++;

            $this->line("Resolved: destination {$destination->id} ({$destination->code}) — reviewed: {$result['reviewed']}, incremented: {$result['incremented']}, failed: {$result['failed']}");
        }

        $this->info("Processed: {$processed}");
        $this->info("Resolved: {$resolved}");
        $this->info("Forced: {$forced}");
        $this->info("Still blocked: {$stillBlocked}");

        Log::info('ProcessDeferredReviews command completed', [
            'processed' => $processed,
            'resolved' => $resolved,
            'forced' => $forced,
            'still_blocked' => $stillBlocked,
        ]);

        return self::SUCCESS;
    }
}
