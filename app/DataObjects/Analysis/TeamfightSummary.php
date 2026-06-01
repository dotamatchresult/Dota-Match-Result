<?php

namespace App\DataObjects\Analysis;

readonly class TeamfightSummary
{
    /**
     * @param  array<string>  $keyHeroes  Hero names who had major impact
     */
    public function __construct(
        public int $index,
        public int $startTime,
        public int $endTime,
        public int $duration,
        public string $outcome, // 'dire_win' | 'radiant_win' | 'even'
        public string $swing, // 'big' | 'medium' | 'small'
        public int $direKills,
        public int $radiantKills,
        public int $direGoldDelta,
        public int $radiantGoldDelta,
        public array $keyHeroes,
    ) {}

    public function getTimeFormatted(): string
    {
        $minutes = floor($this->startTime / 60);
        $seconds = $this->startTime % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    public function isMajorSwing(): bool
    {
        return $this->swing === 'big';
    }
}
