<?php

namespace App\Services\DailyChallenge;

use App\DataObjects\Challenges\EvaluationResult;
use App\Models\ChallengeEvent;
use App\Models\ChallengeNotification;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Services\ChallengeEvaluators\ChallengeEvaluatorRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChallengeProgressService
{
    public function __construct(
        protected ChallengeEvaluatorRegistry $registry
    ) {}

    /**
     * Process a stored match against all applicable active challenges.
     *
     * Evaluates the match for each destination's active challenges,
     * persists progress, and detects/completes finished challenges.
     */
    public function processMatch(DotaMatch $match): void
    {
        $memberIds = $match->members;

        if (empty($memberIds)) {
            return;
        }

        // Load members with their destination
        $members = Member::query()
            ->whereIn('id', $memberIds)
            ->with('destinationConfig')
            ->get();

        if ($members->isEmpty()) {
            return;
        }

        // Group members by destination
        $membersByDestination = $members->groupBy(fn (Member $member) => $member->destinationConfig?->id)
            ->filter(fn ($group, $destinationId) => $destinationId !== null && $destinationId !== '');

        if ($membersByDestination->isEmpty()) {
            return;
        }

        foreach ($membersByDestination as $destinationId => $destinationMembers) {
            $this->processDestination($destinationId, $destinationMembers, $match);
        }
    }

    /**
     * Process all active challenges for a specific destination.
     *
     * @param  \Illuminate\Support\Collection<int, Member>  $destinationMembers
     */
    private function processDestination(int $destinationId, $destinationMembers, DotaMatch $match): void
    {
        $activeChallenges = DestinationChallenge::query()
            ->with('challenge')
            ->where('destination_id', $destinationId)
            ->where('status', 'active')
            ->get();

        foreach ($activeChallenges as $destinationChallenge) {
            $this->processChallenge($destinationChallenge, $destinationMembers, $match);
        }
    }

    /**
     * Evaluate a single challenge against the match and persist results.
     *
     * @param  \Illuminate\Support\Collection<int, Member>  $destinationMembers
     */
    private function processChallenge(
        DestinationChallenge $destinationChallenge,
        $destinationMembers,
        DotaMatch $match
    ): void {
        $challengeCode = $destinationChallenge->challenge->code;

        // Resolve evaluator
        $evaluator = $this->registry->resolve($challengeCode);

        if ($evaluator === null) {
            return;
        }

        // Idempotency check: skip if this match already contributed to this challenge
        $alreadyProcessed = ChallengeEvent::query()
            ->where('destination_challenge_id', $destinationChallenge->id)
            ->where('match_id', $match->id)
            ->where('type', 'progress')
            ->exists();

        if ($alreadyProcessed) {
            return;
        }

        // Evaluate
        $result = $evaluator->evaluate($destinationChallenge, $match, $destinationMembers);

        if (! $result->matched) {
            return;
        }

        // Persist within a transaction
        DB::transaction(function () use ($destinationChallenge, $match, $result, $challengeCode) {
            $valueBefore = $destinationChallenge->current_progress ?? 0;
            $valueAfter = $valueBefore + $result->progressDelta;

            // Update progress
            $destinationChallenge->update([
                'current_progress' => $valueAfter,
                'progress_data' => $this->mergeProgressData(
                    $destinationChallenge->progress_data ?? [],
                    $result
                ),
            ]);

            // Create progress event
            ChallengeEvent::create([
                'destination_challenge_id' => $destinationChallenge->id,
                'match_id' => $match->id,
                'type' => 'progress',
                'value_before' => $valueBefore,
                'value_after' => $valueAfter,
                'payload' => [
                    'progress_delta' => $result->progressDelta,
                    'contributors' => $result->contributors,
                ],
            ]);

            Log::info('Challenge progress updated', [
                'destination_challenge_id' => $destinationChallenge->id,
                'challenge_code' => $destinationChallenge->challenge->code,
                'match_id' => $match->match_id,
                'progress_before' => $valueBefore,
                'progress_after' => $valueAfter,
                'delta' => $result->progressDelta,
            ]);

            // Check completion
            $requirementLower = in_array($challengeCode, ['fast_win']);

            if ($valueAfter >= $destinationChallenge->current_requirement) {
                $this->completeChallenge($destinationChallenge);
            } elseif ($requirementLower && $valueAfter < $destinationChallenge->current_requirement) {
                $this->completeChallenge($destinationChallenge);
            }
        });
    }

    /**
     * Mark a challenge as completed and queue the completion notification.
     */
    private function completeChallenge(DestinationChallenge $destinationChallenge): void
    {
        $destinationChallenge->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        ChallengeEvent::create([
            'destination_challenge_id' => $destinationChallenge->id,
            'match_id' => null,
            'type' => 'completed',
            'value_before' => $destinationChallenge->current_requirement,
            'value_after' => $destinationChallenge->current_progress,
        ]);

        ChallengeNotification::create([
            'destination_challenge_id' => $destinationChallenge->id,
            'type' => 'completed',
            'status' => 'pending',
            'payload' => [
                'challenge_code' => $destinationChallenge->challenge->code,
                'challenge_name' => $destinationChallenge->challenge->name,
                'final_progress' => $destinationChallenge->current_progress,
                'requirement' => $destinationChallenge->current_requirement,
            ],
        ]);

        Log::info('Challenge completed', [
            'destination_challenge_id' => $destinationChallenge->id,
            'challenge_code' => $destinationChallenge->challenge->code,
            'final_progress' => $destinationChallenge->current_progress,
            'requirement' => $destinationChallenge->current_requirement,
        ]);
    }

    /**
     * Merge evaluator progress data into existing progress_data.
     *
     * Preserves the standardized structure: {contributors, matches, metadata}
     *
     * @param  array{contributors?: array, matches?: array, metadata?: array}  $existing
     * @return array{contributors: array, matches: array, best_attempt: int|null, best_member_id: int|null, best_match_id: int|null, metadata: array}
     */
    private function mergeProgressData(array $existing, EvaluationResult $result): array
    {
        $existingContributors = $existing['contributors'] ?? [];
        $existingMatches = $existing['matches'] ?? [];
        $existingMetadata = $existing['metadata'] ?? [];
        $existingBestAttempt = $existing['best_attempt'] ?? null;
        $existingBestMemberId = $existing['best_member_id'] ?? null;
        $existingBestMatchId = $existing['best_match_id'] ?? null;

        // Merge contributors (sum values for same member_id)
        foreach ($result->progressData['contributors'] ?? [] as $memberId => $value) {
            $existingContributors[$memberId] = ($existingContributors[$memberId] ?? 0) + $value;
        }

        // Merge matches (dedup)
        $newMatches = $result->progressData['matches'] ?? [];
        $mergedMatches = array_values(array_unique(array_merge($existingMatches, $newMatches)));

        // Merge metadata
        $mergedMetadata = array_merge($existingMetadata, $result->progressData['metadata'] ?? []);

        // Merge best_attempt — keep the higher value
        $newBestAttempt = $result->progressData['best_attempt'] ?? null;
        $mergedBestAttempt = $existingBestAttempt;
        $mergedBestMemberId = $existingBestMemberId;
        $mergedBestMatchId = $existingBestMatchId;

        if ($newBestAttempt !== null && ($existingBestAttempt === null || $newBestAttempt > $existingBestAttempt)) {
            $mergedBestAttempt = $newBestAttempt;
            $mergedBestMemberId = $result->progressData['best_member_id'] ?? $existingBestMemberId;
            $mergedBestMatchId = $result->progressData['best_match_id'] ?? $existingBestMatchId;
        }

        return [
            'contributors' => $existingContributors,
            'matches' => $mergedMatches,
            'best_attempt' => $mergedBestAttempt,
            'best_member_id' => $mergedBestMemberId,
            'best_match_id' => $mergedBestMatchId,
            'metadata' => $mergedMetadata,
        ];
    }
}
