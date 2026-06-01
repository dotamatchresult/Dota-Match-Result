<?php

namespace App\DataObjects\Analysis;

readonly class DiagnosisContext
{
    /**
     * @param  array<string>  $semanticTags
     * @param  array<string>  $memberHeroes
     * @param  array<string>  $opposingHeroes
     */
    public function __construct(
        public string $matchId,
        public string $gameMode,
        public string $memberTeam,
        public int $durationMinutes,
        public string $lossType,
        public array $semanticTags,
        public float $fightControlRatio,
        public float $objectiveConversionRate,
        public float $enemyPickoffRate,
        public float $protectionIndex,
        public float $damageConcentrationRatio,
        public int $scalingEarlyGold,
        public int $scalingMidGold,
        public int $scalingLateGold,
        public array $memberHeroes,
        public array $opposingHeroes,
        public HeroContributionMatrix $heroContributionMatrix,
        public EnemyThreatMatrix $enemyThreatMatrix,
        public ObjectiveFlowSummary $objectiveFlowSummary,
        public ScalingSummaryDescriptor $scalingSummary,
    ) {}
}
