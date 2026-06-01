<?php

namespace App\DataObjects\Analysis;

readonly class NormalizedMatch
{
    /**
     * @param  array<NormalizedPlayer>  $radiantPlayers
     * @param  array<NormalizedPlayer>  $direPlayers
     * @param  array  $teamfights  Raw teamfights from parsed JSON
     * @param  array  $objectives  Raw objectives from parsed JSON
     * @param  array<int, string>  $heroIndexMap  Maps fight player index (0-9) → hero name for all slots
     */
    public function __construct(
        public string $matchId,
        public string $memberTeam, // 'radiant' or 'dire'
        public bool $memberWon,
        public int $duration,
        public string $gameMode,
        public array $radiantPlayers,
        public array $direPlayers,
        public array $teamfights,
        public array $objectives,
        public array $goldAdvantage,
        public array $xpAdvantage,
        public array $heroIndexMap = [],
    ) {}

    /**
     * Get players from the team containing tracked members
     *
     * @return array<NormalizedPlayer>
     */
    public function getMemberTeamPlayers(): array
    {
        return $this->memberTeam === 'radiant' ? $this->radiantPlayers : $this->direPlayers;
    }

    /**
     * Get players from the enemy team
     *
     * @return array<NormalizedPlayer>
     */
    public function getEnemyTeamPlayers(): array
    {
        return $this->memberTeam === 'radiant' ? $this->direPlayers : $this->radiantPlayers;
    }

    public function getMemberTeamScore(): int
    {
        return array_sum(array_column($this->getMemberTeamPlayers(), 'kills'));
    }

    public function getEnemyTeamScore(): int
    {
        return array_sum(array_column($this->getEnemyTeamPlayers(), 'kills'));
    }
}
