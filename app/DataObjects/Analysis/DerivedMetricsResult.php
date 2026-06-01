<?php

namespace App\DataObjects\Analysis;

readonly class DerivedMetricsResult
{
    /**
     * @param  array<TeamfightSummary>  $fights
     * @param  array<PlayerImpact>  $playerImpacts
     */
    public function __construct(
        public DerivedMetrics $metrics,
        public LossClassification $lossClassification,
        public SemanticTagSet $tags,
        public array $playerImpacts,
        public array $fights,
        public ObjectiveFlow $objectiveFlow,
        public NormalizedMatch $match,
        public HeroContributionMatrix $heroContributionMatrix,
    ) {}

    /** @return array<string> */
    public function getMemberHeroNames(): array
    {
        return array_map(fn (NormalizedPlayer $p) => $p->heroName, $this->match->getMemberTeamPlayers());
    }

    /** @return array<string> */
    public function getOpposingHeroNames(): array
    {
        return array_map(fn (NormalizedPlayer $p) => $p->heroName, $this->match->getEnemyTeamPlayers());
    }
}
