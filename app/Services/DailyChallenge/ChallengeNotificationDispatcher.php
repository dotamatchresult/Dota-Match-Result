<?php

namespace App\Services\DailyChallenge;

use App\Models\ChallengeNotification;
use App\Models\Destination;
use App\Services\Messaging\DestinationMessageService;
use Illuminate\Support\Facades\Log;

class ChallengeNotificationDispatcher
{
    public function __construct(
        protected ChallengeMessageRenderer $renderer,
        protected DestinationMessageService $messageService
    ) {}

    /**
     * Dispatch all pending challenge notifications.
     *
     * Groups completed notifications by destination to send batched messages.
     * Respects scheduled_at for assigned_announcement notifications.
     *
     * @return array{processed: int, sent: int, batched: int, failed: int}
     */
    public function dispatch(): array
    {
        $now = now();

        // Fetch all pending notifications
        $allPending = ChallengeNotification::query()
            ->where('status', 'pending')
            ->with('destinationChallenge.destination', 'destinationChallenge.challenge')
            ->get();

        if ($allPending->isEmpty()) {
            return ['processed' => 0, 'sent' => 0, 'batched' => 0, 'failed' => 0];
        }

        // Split: null destination_challenge_id (backlog_full, recap) vs others
        [$nullDcNotifications, $challengeNotifications] = $allPending->partition(
            fn (ChallengeNotification $n) => $n->destination_challenge_id === null
        );

        // Split challenge notifications by type
        $completedNotifications = $challengeNotifications->filter(fn (ChallengeNotification $n) => $n->type === 'completed');
        $individualNotifications = $challengeNotifications->reject(fn (ChallengeNotification $n) => $n->type === 'completed');

        $sent = 0;
        $batched = 0;
        $failed = 0;
        $processed = 0;

        // Process individual (non-completed, non-backlog) notifications
        foreach ($individualNotifications as $notification) {
            $processed++;

            // Respect scheduled_at for assigned_announcement
            if ($notification->type === 'assigned_announcement' && $notification->scheduled_at && $notification->scheduled_at > $now) {
                continue;
            }

            if ($this->sendNotification($notification)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        // Process null-DC notifications: backlog_full, recap (destination from payload)
        foreach ($nullDcNotifications as $notification) {
            $processed++;

            $destinationId = $notification->payload['destination_id'] ?? null;

            if (! $destinationId) {
                Log::warning('ChallengeNotificationDispatcher: null-DC notification missing destination_id in payload', [
                    'notification_id' => $notification->id,
                    'type' => $notification->type,
                ]);
                $failed++;

                continue;
            }

            $destination = Destination::find($destinationId);

            if (! $destination) {
                Log::warning('ChallengeNotificationDispatcher: null-DC notification references non-existent destination', [
                    'notification_id' => $notification->id,
                    'type' => $notification->type,
                    'destination_id' => $destinationId,
                ]);
                $failed++;

                continue;
            }

            if ($this->sendNotification($notification, $destination)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        // Batch completed notifications by destination
        $completedByDestination = $completedNotifications->groupBy(
            fn (ChallengeNotification $n) => $n->destinationChallenge->destination_id
        );

        foreach ($completedByDestination as $destinationId => $batch) {
            $processed += $batch->count();

            if ($batch->isEmpty()) {
                continue;
            }

            $destination = $batch->first()->destinationChallenge->destination;

            if ($this->sendBatch($batch->values()->all(), $destination)) {
                $sent += $batch->count();
                $batched++;
            } else {
                $failed += $batch->count();
            }
        }

        Log::info('ChallengeNotificationDispatcher: dispatch completed', [
            'processed' => $processed,
            'sent' => $sent,
            'batched' => $batched,
            'failed' => $failed,
        ]);

        return [
            'processed' => $processed,
            'sent' => $sent,
            'batched' => $batched,
            'failed' => $failed,
        ];
    }

    /**
     * Send a single notification and mark it as sent on success.
     */
    private function sendNotification(ChallengeNotification $notification, ?Destination $overrideDestination = null): bool
    {
        try {
            $destination = $overrideDestination ?? $notification->destinationChallenge?->destination;

            if (! $destination) {
                Log::warning('ChallengeNotificationDispatcher: no destination found for notification', [
                    'notification_id' => $notification->id,
                    'type' => $notification->type,
                ]);

                return false;
            }

            $message = $this->renderer->render($notification, $destination);

            if ($message === '') {
                Log::warning('ChallengeNotificationDispatcher: empty rendered message', [
                    'notification_id' => $notification->id,
                    'type' => $notification->type,
                ]);

                return false;
            }

            $this->messageService->send($destination, $message);

            $notification->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('ChallengeNotificationDispatcher: failed to send notification', [
                'notification_id' => $notification->id,
                'type' => $notification->type,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send a batch of completed notifications as a single message.
     *
     * On success, marks all notifications in the batch as sent.
     * On failure, leaves all as pending.
     */
    private function sendBatch(array $notifications, Destination $destination): bool
    {
        try {
            $message = $this->renderer->renderBatch($notifications, $destination);

            if ($message === '') {
                Log::warning('ChallengeNotificationDispatcher: empty rendered batch message', [
                    'batch_size' => count($notifications),
                    'destination_id' => $destination->id,
                ]);

                return false;
            }

            $this->messageService->send($destination, $message);

            $now = now();

            foreach ($notifications as $notification) {
                $notification->update([
                    'status' => 'sent',
                    'sent_at' => $now,
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('ChallengeNotificationDispatcher: failed to send batch notification', [
                'batch_size' => count($notifications),
                'destination_id' => $destination->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
