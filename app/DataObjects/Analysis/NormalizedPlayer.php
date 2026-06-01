<?php

namespace App\DataObjects\Analysis;

readonly class NormalizedPlayer
{
    public function __construct(
        public int $heroId,
        public string $heroName,
        public string $team, // 'radiant' or 'dire'
        public int $kills,
        public int $deaths,
        public int $assists,
        public int $heroDamage,
        public int $towerDamage,
        public int $goldPerMin,
        public int $xpPerMin,
        public int $netWorth,
        // Fight-index mapping: fight['players'][$playerIndex] for this player (0-9)
        public int $playerIndex = 0,
        public int $lastHits = 0,
        public int $level = 0,
        public int $heroHealing = 0,
        public int $roshanKills = 0,
        public int $bountyRuneCount = 0,
    ) {}

    public function getKda(): float
    {
        return $this->deaths > 0
            ? ($this->kills + $this->assists) / $this->deaths
            : ($this->kills + $this->assists);
    }
}
