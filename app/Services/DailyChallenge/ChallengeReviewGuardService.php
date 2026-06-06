<?php

namespace App\Services\DailyChallenge;

use App\DataObjects\Challenges\ReviewDecision;
use App\Models\ChallengeNotification;
use App\Models\Destination;
use App\Models\DotaMatch;
use App\Models\Member;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChallengeReviewGuardService
{
    /**
     * Determine whether review can proceed for a given destination.
     *
     * Checks for Steam-detected matches that:
     * - Involve members of this destination
     * - Finished more than 1 hour ago (finished_at < now - 1h)
     * - Still waiting for OpenDota processing (parse_status = 'pending')
     *
     * If such matches exist, review is blocked. If a review_delayed notification
     * already exists for this destination today and exceeds the force threshold,
     * review is forced instead.
     */
    public function canReviewDestination(Destination $destination): ReviewDecision
    {
        $memberIds = Member::query()
            ->whereHas('destinationConfig', function ($query) use ($destination) {
                $query->where('id', $destination->id);
            })
            ->pluck('id')
            ->toArray();

        if (empty($memberIds)) {
            Log::info('ChallengeReviewGuardService: no members for destination, allowing review', [
                'destination_id' => $destination->id,
                'destination_code' => $destination->code,
            ]);

            return ReviewDecision::allowed();
        }

        $blockingMatches = $this->findBlockingMatches($memberIds);

        if ($blockingMatches->isEmpty()) {
            return ReviewDecision::allowed();
        }

        $blockingMatchIds = $blockingMatches->pluck('id')->toArray();

        // Check if a review_delayed notification already exists for this destination today
        $timezone = config('dota.daily_challenge.timezone', 'Asia/Jakarta');
        $today = now($timezone)->toDateString();

        $existingDelayNotification = ChallengeNotification::query()
            ->where('type', 'review_delayed')
            ->where('status', 'pending')
            ->whereDate('created_at', $today)
            ->where('payload->destination_id', $destination->id)
            ->first();

        if ($existingDelayNotification !== null) {
            $forceAfterHours = (int) config('dota.daily_challenge.review_force_after_hours', 12);
            $threshold = $existingDelayNotification->created_at->addHours($forceAfterHours);

            if (now()->gte($threshold)) {
                Log::info('ChallengeReviewGuardService: force review threshold exceeded', [
                    'destination_id' => $destination->id,
                    'blocking_match_ids' => $blockingMatchIds,
                    'delay_notification_created_at' => $existingDelayNotification->created_at->toIso8601String(),
                    'force_after_hours' => $forceAfterHours,
                ]);

                return ReviewDecision::forceReview($blockingMatchIds);
            }
        }

        Log::info('ChallengeReviewGuardService: review blocked by unresolved matches', [
            'destination_id' => $destination->id,
            'blocking_match_ids' => $blockingMatchIds,
            'blocking_match_count' => count($blockingMatchIds),
        ]);

        return ReviewDecision::blocked($blockingMatchIds);
    }

    /**
     * Find dota_matches that are blocking review for the given member IDs.
     *
     * Criteria: match involves one of the members, finished > 1 hour ago,
     * and parse_status is 'pending' (OpenDota hasn't processed it yet).
     *
     * Uses driver-aware JSON overlap query for cross-database compatibility.
     *
     * @param  array<int>  $memberIds
     * @return Collection<int, DotaMatch>
     */
    private function findBlockingMatches(array $memberIds): Collection
    {
        $driver = DB::getDriverName();

        return DotaMatch::query()
            ->where('parse_status', 'pending')
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', now()->subHour())
            ->where(function ($query) use ($memberIds, $driver) {
                foreach ($memberIds as $memberId) {
                    if ($driver === 'mysql') {
                        $query->orWhereJsonContains('members', (int) $memberId);
                    } else {
                        // SQLite: JSON arrays store integers, match with LIKE
                        $query->orWhere('members', 'like', '%'.$memberId.'%');
                    }
                }
            })
            ->get();
    }
}
