<?php

namespace App\DataObjects\Analysis;

use App\Enums\ImpactOnUs;
use App\Enums\ThreatType;

readonly class EnemyThreatEntry
{
    /**
     * @param  array<ImpactOnUs>  $impactOnUs  1–2 impact types max
     */
    public function __construct(
        public string $heroName,
        public ThreatType $threatType,
        public array $impactOnUs,
        public float $damageShareEnemy,
        public float $killContribution,
    ) {}
}
