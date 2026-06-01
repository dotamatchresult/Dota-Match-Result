<?php

namespace App\DataObjects\Analysis;

readonly class DiagnosisResult
{
    /**
     * @param  array<string>  $keyFactors
     * @param  array<array{hero: string, role: string, issue: string, team_impact: string}>  $heroFindings  Per-hero findings from Stage 1 LLM
     * @param  array<array{hero: string, threat_type: string, how_it_hurt_us: string}>  $enemyPressureSources  Enemy pressure layer
     * @param  array<string, mixed>  $objectiveConsequences  Objective layer summary
     * @param  array<string, string>  $scalingAssessment  Scaling trend layer
     */
    public function __construct(
        /** Loss type name as returned or confirmed by LLM */
        public string $lossTypeConfirmed,
        public string $rootCause,
        public array $keyFactors,
        public string $momentum,
        public int $tokensUsed,
        public array $heroFindings = [],
        public array $enemyPressureSources = [],
        public array $objectiveConsequences = [],
        public array $scalingAssessment = [],
    ) {}
}
