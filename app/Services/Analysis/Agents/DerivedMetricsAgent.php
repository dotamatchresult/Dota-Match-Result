<?php

namespace App\Services\Analysis\Agents;

use App\DataObjects\Analysis\DerivedMetricsResult;
use App\DataObjects\Analysis\NormalizedMatch;
use App\DataObjects\Analysis\ObjectiveFlow;
use App\DataObjects\Analysis\PlayerImpact;
use App\DataObjects\Analysis\TeamfightSummary;
use App\Services\Analysis\Metrics\HeroContributionService;
use App\Services\Analysis\Metrics\LossClassifierService;
use App\Services\Analysis\Metrics\MetricEngineService;
use App\Services\Analysis\Metrics\SemanticTaggerService;

class DerivedMetricsAgent
{
    public function __construct(
        protected MetricEngineService $metricEngine,
        protected LossClassifierService $lossClassifier,
        protected SemanticTaggerService $tagger,
        protected HeroContributionService $heroContribution,
    ) {}

    /**
     * @param  array<TeamfightSummary>  $teamfightSummaries
     * @param  array<PlayerImpact>  $playerImpacts
     */
    public function compute(
        NormalizedMatch $match,
        array $teamfightSummaries,
        ObjectiveFlow $objectiveFlow,
        array $playerImpacts
    ): DerivedMetricsResult {
        $metrics = $this->metricEngine->compute($match, $teamfightSummaries, $objectiveFlow, $playerImpacts);
        $tags = $this->tagger->tag($metrics, $objectiveFlow, $match);
        $lossClassification = $this->lossClassifier->classify($metrics, $tags);
        $heroContributionMatrix = $this->heroContribution->build($match, $playerImpacts, $teamfightSummaries);

        return new DerivedMetricsResult(
            metrics: $metrics,
            lossClassification: $lossClassification,
            tags: $tags,
            playerImpacts: $playerImpacts,
            fights: $teamfightSummaries,
            objectiveFlow: $objectiveFlow,
            match: $match,
            heroContributionMatrix: $heroContributionMatrix,
        );
    }
}
