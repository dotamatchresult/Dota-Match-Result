<?php

namespace App\DataObjects\Analysis;

readonly class ObjectiveFlow
{
    /**
     * @param  array<ObjectiveEvent>  $events
     * @param  array<string>  $momentumShifts
     */
    public function __construct(
        public array $events,
        public array $momentumShifts,
        public ?string $criticalObjective = null,
    ) {}
}
