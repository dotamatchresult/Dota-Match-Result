<?php

namespace App\DataObjects\Analysis;

readonly class PlayerImpact
{
    public function __construct(
        public string $heroName,
        public int $kills,
        public int $deaths,
        public int $assists,
        public int $heroDamage,
        public int $towerDamage,
        public float $impactScore,
        public string $impactLabel, // 'high' | 'medium' | 'low'
        public float $fightParticipation, // 0.0 to 1.0
        public int $deathsInLosingFights,
        public int $deathsInAllFights = 0,
        public int $netWorth = 0,
    ) {}

    public function getKda(): float
    {
        return $this->deaths > 0
            ? ($this->kills + $this->assists) / $this->deaths
            : ($this->kills + $this->assists);
    }
}
