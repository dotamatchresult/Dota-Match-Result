<?php

namespace App\Services\DailyChallenge;

use App\Models\Challenge;
use App\Models\ChallengeEvent;
use App\Models\ChallengeNotification;
use App\Models\Destination;
use App\Models\DestinationChallenge;
use App\Models\Hero;
use App\Models\Item;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChallengeAssignmentService
{
    /**
     * Assign a daily challenge to the given destination.
     *
     * Returns the created DestinationChallenge, or null if assignment was skipped
     * (due to idempotency, limit reached, or no challenges available).
     */
    public function assignForDestination(Destination $destination): ?DestinationChallenge
    {
        $timezone = config('dota.daily_challenge.timezone', 'Asia/Jakarta');
        $today = $this->todayInTimezone($timezone);

        // --- Rule 2: Enforce active limit ---
        $maxActive = (int) config('dota.daily_challenge.max_active_per_destination', 5);
        $activeCount = DestinationChallenge::query()
            ->where('destination_id', $destination->id)
            ->where('status', 'active')
            ->count();

        if ($activeCount >= $maxActive) {
            Log::info('Daily challenge assignment skipped: max active limit reached', [
                'destination_id' => $destination->id,
                'destination_code' => $destination->code,
                'active_challenge_count' => $activeCount,
                'max_allowed' => $maxActive,
            ]);

            $this->createBacklogNotification($destination);

            return null;
        }

        // --- Rule 5: Idempotency ---
        $existing = DestinationChallenge::query()
            ->where('destination_id', $destination->id)
            ->whereDate('assigned_date', $today)
            ->exists();

        if ($existing) {
            Log::info('Daily challenge assignment skipped: already assigned today', [
                'destination_id' => $destination->id,
                'destination_code' => $destination->code,
                'assigned_date' => $today,
            ]);

            return null;
        }

        // --- Rule 3 & 4: Select challenge ---
        $challenge = $this->selectChallenge($destination);

        if (! $challenge) {
            Log::warning('Daily challenge assignment skipped: no active challenges available', [
                'destination_id' => $destination->id,
                'destination_code' => $destination->code,
            ]);

            return null;
        }

        // --- Rule 6: Resolve runtime configuration (e.g. random item for item_win) ---
        $runtimeConfig = $this->resolveRuntimeConfig($challenge);

        // --- Create assignment, event, and notification in a transaction ---
        return DB::transaction(function () use ($destination, $challenge, $timezone, $today, $runtimeConfig) {
            $destinationChallenge = DestinationChallenge::create([
                'destination_id' => $destination->id,
                'challenge_id' => $challenge->id,
                'assigned_date' => $today,
                'status' => 'active',
                'current_requirement' => $challenge->base_requirement,
                'progress_data' => $runtimeConfig['progress_data'],
                'failed_days' => 0,
            ]);

            $eventPayload = array_merge([
                'challenge_code' => $challenge->code,
                'challenge_name' => $challenge->name,
            ], $runtimeConfig['event_meta']);

            ChallengeEvent::create([
                'destination_challenge_id' => $destinationChallenge->id,
                'match_id' => null,
                'type' => 'assigned',
                'value_before' => 0,
                'value_after' => $challenge->base_requirement,
                'payload' => $eventPayload,
            ]);

            $scheduledAt = Carbon::now($timezone)
                ->setTimeFromTimeString(config('dota.daily_challenge.announcement_time', '08:00'));

            $notificationPayload = array_merge([
                'challenge_code' => $challenge->code,
                'challenge_name' => $challenge->name,
                'requirement' => $challenge->base_requirement,
                'category' => $challenge->category,
            ], $runtimeConfig['notification_meta']);

            ChallengeNotification::create([
                'destination_challenge_id' => $destinationChallenge->id,
                'type' => 'assigned_announcement',
                'scheduled_at' => $scheduledAt->setTimezone('UTC'),
                'status' => 'pending',
                'payload' => $notificationPayload,
            ]);

            Log::info('Daily challenge assigned', [
                'destination_id' => $destination->id,
                'destination_code' => $destination->code,
                'challenge_id' => $challenge->id,
                'challenge_code' => $challenge->code,
                'assignment_date' => $today,
                'base_requirement' => $challenge->base_requirement,
                'active_challenge_count' => DestinationChallenge::query()
                    ->where('destination_id', $destination->id)
                    ->where('status', 'active')
                    ->count(),
            ]);

            return $destinationChallenge;
        });
    }

    /**
     * Resolve runtime configuration for a challenge before assignment.
     *
     * For item_win challenges, picks a random item with cost >= 4000
     * and stores the item_id in progress_data and event/notification payloads.
     *
     * @return array{progress_data: array, event_meta: array, notification_meta: array}
     */
    private function resolveRuntimeConfig(Challenge $challenge): array
    {
        $progressData = [];
        $eventMeta = [];
        $notificationMeta = [];

        if ($challenge->code === 'item_win') {
            $item = Item::query()
                ->where('cost', '>=', 4000)
                ->inRandomOrder()
                ->first();

            if ($item) {
                $progressData = ['metadata' => ['item_id' => $item->item_id]];
                $eventMeta = ['item_id' => $item->item_id, 'item_name' => $item->dname ?? $item->name];
                $notificationMeta = ['item_id' => $item->item_id, 'item_name' => $item->dname ?? $item->name];

                Log::info('Daily challenge: randomized item for item_win', [
                    'challenge_code' => $challenge->code,
                    'item_id' => $item->item_id,
                    'item_name' => $item->dname ?? $item->name,
                ]);
            } else {
                Log::warning('Daily challenge: no items with cost >= 4000 found for item_win', [
                    'challenge_code' => $challenge->code,
                ]);
            }
        }

        if ($challenge->code === 'hero_win') {
            $hero = Hero::query()->inRandomOrder()->first();

            if ($hero) {
                $progressData = ['metadata' => ['hero_id' => $hero->hero_id]];
                $eventMeta = ['hero_id' => $hero->hero_id, 'hero_name' => $hero->localized_name ?? $hero->name];
                $notificationMeta = ['hero_id' => $hero->hero_id, 'hero_name' => $hero->localized_name ?? $hero->name];

                Log::info('Daily challenge: randomized hero for hero_win', [
                    'challenge_code' => $challenge->code,
                    'hero_id' => $hero->hero_id,
                    'hero_name' => $hero->localized_name ?? $hero->name,
                ]);
            } else {
                Log::warning('Daily challenge: no heroes found for hero_win', [
                    'challenge_code' => $challenge->code,
                ]);
            }
        }

        return [
            'progress_data' => $progressData,
            'event_meta' => $eventMeta,
            'notification_meta' => $notificationMeta,
        ];
    }

    /**
     * Select an appropriate challenge for the destination.
     *
     * Prefers challenges not assigned within the cooldown period.
     * Falls back to any active challenge if the pool is exhausted.
     */
    private function selectChallenge(Destination $destination): ?Challenge
    {
        $cooldownDays = (int) config('dota.daily_challenge.assignment_history_days', 14);
        $timezone = config('dota.daily_challenge.timezone', 'Asia/Jakarta');
        $today = $this->todayInTimezone($timezone);
        $cooldownDate = Carbon::now($timezone)->subDays($cooldownDays)->toDateString();

        // Gather recently assigned challenge IDs within the cooldown window
        $recentChallengeIds = DestinationChallenge::query()
            ->where('destination_id', $destination->id)
            ->whereDate('assigned_date', '>=', $cooldownDate)
            ->whereDate('assigned_date', '<', $today)
            ->pluck('challenge_id')
            ->unique()
            ->toArray();

        // --- Rule 3: Prefer challenges not recently assigned ---
        $candidate = Challenge::query()
            ->active()
            ->when(! empty($recentChallengeIds), fn ($q) => $q->whereNotIn('id', $recentChallengeIds))
            ->inRandomOrder()
            ->first();

        // --- Rule 4: Fallback to any active challenge if pool exhausted ---
        if (! $candidate) {
            Log::info('Daily challenge assignment: cooldown pool exhausted, using fallback', [
                'destination_id' => $destination->id,
                'destination_code' => $destination->code,
                'recently_assigned_count' => count($recentChallengeIds),
            ]);

            $candidate = Challenge::query()
                ->active()
                ->inRandomOrder()
                ->first();
        }

        return $candidate;
    }

    /**
     * Create a backlog notification when the active challenge limit is reached.
     */
    private function createBacklogNotification(Destination $destination): void
    {
        ChallengeNotification::create([
            'destination_challenge_id' => null,
            'type' => 'backlog_full',
            'status' => 'pending',
            'payload' => [
                'destination_id' => $destination->id,
                'destination_code' => $destination->code,
            ],
        ]);
    }

    /**
     * Get today's date string in the configured timezone.
     */
    private function todayInTimezone(string $timezone): string
    {
        return Carbon::now($timezone)->toDateString();
    }
}
