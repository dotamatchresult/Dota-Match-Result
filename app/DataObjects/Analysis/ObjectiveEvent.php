<?php

namespace App\DataObjects\Analysis;

readonly class ObjectiveEvent
{
    public function __construct(
        public int $time,
        public string $type, // 'tower' | 'barracks' | 'roshan' | 'firstblood'
        public string $team, // 'radiant' | 'dire'
        public ?int $linkedFightIndex = null,
    ) {}

    public function getTimeFormatted(): string
    {
        $minutes = floor($this->time / 60);
        $seconds = $this->time % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }
}
