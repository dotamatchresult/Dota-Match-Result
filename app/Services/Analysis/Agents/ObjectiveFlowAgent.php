<?php

namespace App\Services\Analysis\Agents;

use App\DataObjects\Analysis\NormalizedMatch;
use App\DataObjects\Analysis\ObjectiveEvent;
use App\DataObjects\Analysis\ObjectiveFlow;
use App\DataObjects\Analysis\TeamfightSummary;

class ObjectiveFlowAgent
{
    /**
     * @param  array<TeamfightSummary>  $teamfightSummaries
     */
    public function analyze(NormalizedMatch $match, array $teamfightSummaries): ObjectiveFlow
    {
        $events = [];
        $momentumShifts = [];
        $criticalObjective = null;

        foreach ($match->objectives as $objective) {
            $time = $objective['time'] ?? 0;
            $type = $this->mapObjectiveType($objective);
            $team = $this->determineObjectiveTeam($objective);

            if (! $type || ! $team) {
                continue;
            }

            $linkedFight = $this->findNearestFight($time, $teamfightSummaries);

            $events[] = new ObjectiveEvent(
                time: $time,
                type: $type,
                team: $team,
                linkedFightIndex: $linkedFight?->index,
            );
        }

        // Detect momentum shifts (3+ consecutive team objectives)
        $momentumShifts = $this->detectMomentumShifts($events);

        // Find critical objective (Roshan or Barracks that changed game)
        $criticalObjective = $this->findCriticalObjective($events, $match);

        return new ObjectiveFlow(
            events: $events,
            momentumShifts: $momentumShifts,
            criticalObjective: $criticalObjective,
        );
    }

    private function mapObjectiveType(array $objective): ?string
    {
        $key = $objective['key'] ?? '';
        $type = $objective['type'] ?? '';

        if (str_contains($key, 'tower') || $type === 'building_kill') {
            return 'tower';
        }

        if (str_contains($key, 'barracks') || str_contains($key, 'rax')) {
            return 'barracks';
        }

        if ($type === 'CHAT_MESSAGE_ROSHAN_KILL') {
            return 'roshan';
        }

        if ($type === 'CHAT_MESSAGE_FIRSTBLOOD') {
            return 'firstblood';
        }

        return null;
    }

    private function determineObjectiveTeam(array $objective): ?string
    {
        $key = $objective['key'] ?? '';
        $playerSlot = $objective['player_slot'] ?? null;

        // Building kills
        if (str_contains($key, 'goodguys')) {
            return 'radiant';
        }

        if (str_contains($key, 'badguys')) {
            return 'dire';
        }

        // Player-based objectives (Roshan, firstblood)
        if ($playerSlot !== null) {
            return $playerSlot < 128 ? 'radiant' : 'dire';
        }

        return null;
    }

    private function findNearestFight(int $objectiveTime, array $teamfightSummaries): ?TeamfightSummary
    {
        $nearest = null;
        $minDiff = 90; // 90 seconds threshold

        foreach ($teamfightSummaries as $fight) {
            $diff = abs($fight->startTime - $objectiveTime);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $nearest = $fight;
            }
        }

        return $nearest;
    }

    /**
     * @param  array<ObjectiveEvent>  $events
     * @return array<string>
     */
    private function detectMomentumShifts(array $events): array
    {
        $shifts = [];
        $consecutiveRadiant = 0;
        $consecutiveDire = 0;

        foreach ($events as $event) {
            if ($event->type === 'firstblood') {
                continue;
            }

            if ($event->team === 'radiant') {
                $consecutiveRadiant++;
                $consecutiveDire = 0;

                if ($consecutiveRadiant === 3) {
                    $shifts[] = "Radiant gained momentum around {$event->getTimeFormatted()}";
                }
            } else {
                $consecutiveDire++;
                $consecutiveRadiant = 0;

                if ($consecutiveDire === 3) {
                    $shifts[] = "Dire gained momentum around {$event->getTimeFormatted()}";
                }
            }
        }

        return $shifts;
    }

    /**
     * @param  array<ObjectiveEvent>  $events
     */
    private function findCriticalObjective(array $events, NormalizedMatch $match): ?string
    {
        $enemyTeam = $match->memberTeam === 'radiant' ? 'dire' : 'radiant';

        // Find first Roshan taken by enemy
        foreach ($events as $event) {
            if ($event->type === 'roshan' && $event->team === $enemyTeam) {
                return "Enemy Roshan at {$event->getTimeFormatted()}";
            }
        }

        // Find first barracks lost
        foreach ($events as $event) {
            if ($event->type === 'barracks' && $event->team === $enemyTeam) {
                return "Lost barracks at {$event->getTimeFormatted()}";
            }
        }

        return null;
    }
}
