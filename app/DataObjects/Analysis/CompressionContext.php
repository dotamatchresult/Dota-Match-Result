<?php

namespace App\DataObjects\Analysis;

readonly class CompressionContext
{
    /**
     * @param  array<string>  $keyFactors
     * @param  array<string>  $memberHeroes
     * @param  array<array{hero: string, role: string, issue: string, team_impact: string}>  $heroFindings  Forwarded from DiagnosisResult to ground bullet points in hero names
     */
    public function __construct(
        public string $gameMode,
        public string $memberTeam,
        public int $durationMinutes,
        public string $lossTypeConfirmed,
        public string $rootCause,
        public array $keyFactors,
        public string $momentum,
        public array $memberHeroes,
        public array $heroFindings = [],
    ) {}
}
