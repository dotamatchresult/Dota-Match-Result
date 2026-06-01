<?php

namespace App\DataObjects\Analysis;

readonly class ObjectiveFlowSummary
{
    public function __construct(
        /** 'us' | 'enemy' | 'contested' | 'none' */
        public string $roshanControl,
        /** Reused from ObjectiveFlow::$momentumShifts[0], or null */
        public ?string $mapControlShift,
        /** Count of enemy objectives taken within 90s after an unlinked member death */
        public int $postPickoffObjectives,
        /** Count of our fight wins with no member objective within 120s */
        public int $missedObjectivesAfterWin,
        /** First barracks lost or enemy Roshan, forwarded from ObjectiveFlow */
        public ?string $criticalObjective,
    ) {}

    /**
     * Compact key-value payload for LLM — no raw event arrays.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'roshan_control' => $this->roshanControl,
            'map_control_shift' => $this->mapControlShift ?? 'none',
            'post_pickoff_objectives_lost' => $this->postPickoffObjectives,
            'missed_objectives_after_wins' => $this->missedObjectivesAfterWin,
            'critical_event' => $this->criticalObjective ?? 'none',
        ];
    }
}
