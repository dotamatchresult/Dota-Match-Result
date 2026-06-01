<?php

namespace App\Services\Analysis\Metrics;

use App\DataObjects\Analysis\NormalizedMatch;
use App\DataObjects\Analysis\ObjectiveEvent;
use App\DataObjects\Analysis\ObjectiveFlow;
use App\DataObjects\Analysis\ObjectiveFlowSummary;
use App\DataObjects\Analysis\TeamfightSummary;

class ObjectiveFlowSummaryBuilder
{
    /**
     * @param  array<TeamfightSummary>  $teamfightSummaries
     */
    public function build(ObjectiveFlow $flow, array $teamfightSummaries, NormalizedMatch $match): ObjectiveFlowSummary
    {
        return new ObjectiveFlowSummary(
            roshanControl: $this->computeRoshanControl($flow->events, $match->memberTeam),
            mapControlShift: $flow->momentumShifts[0] ?? null,
            postPickoffObjectives: $this->countPostPickoffObjectives($flow->events, $match->memberTeam),
            missedObjectivesAfterWin: $this->countMissedObjectivesAfterWin($flow->events, $teamfightSummaries, $match->memberTeam),
            criticalObjective: $flow->criticalObjective,
        );
    }

    /**
     * @param  array<ObjectiveEvent>  $events
     */
    private function computeRoshanControl(array $events, string $memberTeam): string
    {
        $roshanEvents = array_filter($events, fn (ObjectiveEvent $e) => $e->type === 'roshan');

        if (empty($roshanEvents)) {
            return 'none';
        }

        $usTeam = $memberTeam;
        $enemyTeam = $memberTeam === 'radiant' ? 'dire' : 'radiant';

        $ourRoshans = count(array_filter($roshanEvents, fn (ObjectiveEvent $e) => $e->team === $usTeam));
        $enemyRoshans = count(array_filter($roshanEvents, fn (ObjectiveEvent $e) => $e->team === $enemyTeam));

        if ($ourRoshans > 0 && $enemyRoshans > 0) {
            return 'contested';
        }

        if ($ourRoshans > 0) {
            return 'us';
        }

        if ($enemyRoshans > 0) {
            return 'enemy';
        }

        return 'none';
    }

    /**
     * Count enemy objectives where the event has no linked fight (proxy for post-isolation objectives).
     *
     * @param  array<ObjectiveEvent>  $events
     */
    private function countPostPickoffObjectives(array $events, string $memberTeam): int
    {
        $enemyTeam = $memberTeam === 'radiant' ? 'dire' : 'radiant';

        return count(array_filter(
            $events,
            fn (ObjectiveEvent $e) => $e->type !== 'firstblood'
                && $e->team === $enemyTeam
                && $e->linkedFightIndex === null
        ));
    }

    /**
     * Count our fight wins that had no follow-up objective within 120 seconds.
     *
     * @param  array<ObjectiveEvent>  $events
     * @param  array<TeamfightSummary>  $teamfightSummaries
     */
    private function countMissedObjectivesAfterWin(
        array $events,
        array $teamfightSummaries,
        string $memberTeam
    ): int {
        $winOutcome = "{$memberTeam}_win";
        $missed = 0;

        foreach ($teamfightSummaries as $fight) {
            if ($fight->outcome !== $winOutcome) {
                continue;
            }

            $windowEnd = $fight->endTime + 120;
            $hasFollowUp = false;

            foreach ($events as $event) {
                if (
                    $event->type !== 'firstblood'
                    && $event->team === $memberTeam
                    && $event->time > $fight->endTime
                    && $event->time <= $windowEnd
                ) {
                    $hasFollowUp = true;
                    break;
                }
            }

            if (! $hasFollowUp) {
                $missed++;
            }
        }

        return $missed;
    }
}
