<?php

namespace App\Services\Analysis;

use App\DataObjects\Analysis\CompressionContext;
use App\DataObjects\Analysis\DerivedMetricsResult;
use App\DataObjects\Analysis\DiagnosisContext;
use App\DataObjects\Analysis\DiagnosisResult;
use App\DataObjects\Analysis\NormalizedMatch;
use App\DataObjects\Analysis\ObjectiveFlow;
use App\Services\Analysis\Agents\BulletCompressorAgent;
use App\Services\Analysis\Agents\DerivedMetricsAgent;
use App\Services\Analysis\Agents\DiagnosisAgent;
use App\Services\Analysis\Agents\MatchGatekeeperAgent;
use App\Services\Analysis\Agents\NormalizerAgent;
use App\Services\Analysis\Agents\ObjectiveFlowAgent;
use App\Services\Analysis\Agents\PlayerImpactAgent;
use App\Services\Analysis\Agents\TeamfightAggregatorAgent;
use App\Services\Analysis\Metrics\EnemyThreatService;
use App\Services\Analysis\Metrics\ObjectiveFlowSummaryBuilder;
use App\Services\Analysis\Metrics\ScalingSummaryBuilder;
use Illuminate\Support\Facades\Log;

class MatchAnalysisPipeline
{
    public function __construct(
        protected MatchGatekeeperAgent $gatekeeper,
        protected NormalizerAgent $normalizer,
        protected TeamfightAggregatorAgent $teamfightAggregator,
        protected ObjectiveFlowAgent $objectiveFlowAgent,
        protected PlayerImpactAgent $playerImpact,
        protected DerivedMetricsAgent $derivedMetrics,
        protected DiagnosisAgent $diagnosis,
        protected BulletCompressorAgent $bulletCompressor,
        protected EnemyThreatService $enemyThreat,
        protected ObjectiveFlowSummaryBuilder $objectiveFlowSummaryBuilder,
        protected ScalingSummaryBuilder $scalingSummaryBuilder,
    ) {}

    /**
     * Execute full 8-stage analysis pipeline.
     *
     * @param  array  $matchData  Raw parsed match JSON
     * @param  array  $memberSteamIds  Array of member Steam IDs
     * @return array|null Analysis result or null on failure
     */
    public function analyze(array $matchData, array $memberSteamIds): ?array
    {
        $matchId = $matchData['match_id'] ?? 'unknown';

        Log::info('Starting match analysis pipeline v2', ['match_id' => $matchId]);

        // Agent 1: Gatekeeper
        $gatekeeperResult = $this->gatekeeper->validate($matchData, $memberSteamIds);
        if (! $gatekeeperResult->passed) {
            Log::info('Match rejected by gatekeeper', [
                'match_id' => $matchId,
                'reason' => $gatekeeperResult->reason,
                'code' => $gatekeeperResult->rejectCode,
            ]);

            return null;
        }

        // Agent 2: Normalizer
        $normalized = $this->normalizer->normalize($matchData, $memberSteamIds);

        // Agent 3: Teamfight Aggregator
        $teamfightSummaries = $this->teamfightAggregator->aggregate($normalized);

        // Agent 4: Objective Flow
        $objectiveFlow = $this->objectiveFlowAgent->analyze($normalized, $teamfightSummaries);

        // Agent 5: Player Impact
        $playerImpacts = $this->playerImpact->calculate($normalized, $teamfightSummaries);

        // Agent 6: Derived Metrics (deterministic — metrics, loss classification, semantic tags)
        $metricsResult = $this->derivedMetrics->compute(
            $normalized,
            $teamfightSummaries,
            $objectiveFlow,
            $playerImpacts
        );

        // Agent 6b: Context Enrichment (deterministic — enemy threats, objective flow summary, scaling)
        $diagnosisContext = $this->buildDiagnosisContext($metricsResult, $normalized, $objectiveFlow, $teamfightSummaries);

        // Agent 7: LLM Stage 1 — Structured Diagnosis (stored internally)
        $diagnosisResult = $this->diagnosis->diagnose($diagnosisContext);

        if (! $diagnosisResult) {
            Log::error('DiagnosisAgent (Stage 1) failed', ['match_id' => $matchId]);

            return null;
        }

        // Agent 8: LLM Stage 2 — Bullet Compression (user-facing)
        $compressionContext = $this->buildCompressionContext($metricsResult, $diagnosisResult, $normalized);
        $bullets = $this->bulletCompressor->compress($compressionContext);

        if (! $bullets) {
            Log::error('BulletCompressorAgent (Stage 2) failed', ['match_id' => $matchId]);

            return null;
        }

        $stage1Tokens = $diagnosisResult->tokensUsed;
        $stage2Tokens = $bullets->tokensUsed;
        $m = $metricsResult->metrics;

        return [
            'analysis_text' => $bullets->bulletText,
            'computed_metrics' => [
                'pipeline_version' => '2.0',
                'loss_type' => $metricsResult->lossClassification->lossType->value,
                'loss_type_confidence' => $metricsResult->lossClassification->confidence,
                'semantic_tags' => $metricsResult->tags->activeTags,
                'derived_metrics' => [
                    'fight_control_ratio' => $m->fightControlRatio,
                    'objective_conversion_rate' => $m->objectiveConversionRate,
                    'enemy_pickoff_rate' => $m->enemyPickoffRate,
                    'protection_index' => $m->protectionIndex,
                    'damage_concentration_ratio' => $m->damageConcentrationRatio,
                    'scaling_early_gold' => $m->scalingEarlyGold,
                    'scaling_mid_gold' => $m->scalingMidGold,
                    'scaling_late_gold' => $m->scalingLateGold,
                ],
                'diagnosis' => [
                    'loss_type_confirmed' => $diagnosisResult->lossTypeConfirmed,
                    'root_cause' => $diagnosisResult->rootCause,
                    'key_factors' => $diagnosisResult->keyFactors,
                    'momentum' => $diagnosisResult->momentum,
                    'enemy_pressure_sources' => $diagnosisResult->enemyPressureSources,
                    'objective_consequences' => $diagnosisResult->objectiveConsequences,
                    'scaling_assessment' => $diagnosisResult->scalingAssessment,
                    'stage1_tokens' => $stage1Tokens,
                ],
                'teamfight_summaries' => array_map(fn ($f) => [
                    'time' => $f->getTimeFormatted(),
                    'outcome' => $f->outcome,
                    'swing' => $f->swing,
                    'key_heroes' => $f->keyHeroes,
                ], $teamfightSummaries),
                'player_impacts' => array_map(fn ($p) => [
                    'hero' => $p->heroName,
                    'impact_score' => $p->impactScore,
                    'impact_label' => $p->impactLabel,
                    'kda' => round($p->getKda(), 2),
                    'deaths_in_losing_fights' => $p->deathsInLosingFights,
                    'deaths_in_all_fights' => $p->deathsInAllFights,
                ], $playerImpacts),
                'objective_flow' => [
                    'momentum_shifts' => $objectiveFlow->momentumShifts,
                    'critical_objective' => $objectiveFlow->criticalObjective,
                ],
                'hero_contribution_matrix' => $metricsResult->heroContributionMatrix->toStorable(),
            ],
            'metadata' => [
                'analyzed_at' => now()->toIso8601String(),
                'pipeline_version' => '2.0',
                'stage1_tokens' => $stage1Tokens,
                'stage2_tokens' => $stage2Tokens,
                'tokens_used' => $stage1Tokens + $stage2Tokens,
                'model' => $bullets->model,
            ],
        ];
    }

    /**
     * @param  array<\App\DataObjects\Analysis\TeamfightSummary>  $teamfightSummaries
     */
    private function buildDiagnosisContext(
        DerivedMetricsResult $metricsResult,
        NormalizedMatch $normalized,
        ObjectiveFlow $objectiveFlow,
        array $teamfightSummaries
    ): DiagnosisContext {
        $enemyThreatMatrix = $this->enemyThreat->build($normalized);
        $objectiveFlowSummary = $this->objectiveFlowSummaryBuilder->build($objectiveFlow, $teamfightSummaries, $normalized);
        $scalingSummary = $this->scalingSummaryBuilder->build($normalized);

        return new DiagnosisContext(
            matchId: $normalized->matchId,
            gameMode: $normalized->gameMode,
            memberTeam: $normalized->memberTeam,
            durationMinutes: (int) floor($normalized->duration / 60),
            lossType: $metricsResult->lossClassification->lossType->value,
            semanticTags: $metricsResult->tags->activeTags,
            fightControlRatio: $metricsResult->metrics->fightControlRatio,
            objectiveConversionRate: $metricsResult->metrics->objectiveConversionRate,
            enemyPickoffRate: $metricsResult->metrics->enemyPickoffRate,
            protectionIndex: $metricsResult->metrics->protectionIndex,
            damageConcentrationRatio: $metricsResult->metrics->damageConcentrationRatio,
            scalingEarlyGold: $metricsResult->metrics->scalingEarlyGold,
            scalingMidGold: $metricsResult->metrics->scalingMidGold,
            scalingLateGold: $metricsResult->metrics->scalingLateGold,
            memberHeroes: $metricsResult->getMemberHeroNames(),
            opposingHeroes: $metricsResult->getOpposingHeroNames(),
            heroContributionMatrix: $metricsResult->heroContributionMatrix,
            enemyThreatMatrix: $enemyThreatMatrix,
            objectiveFlowSummary: $objectiveFlowSummary,
            scalingSummary: $scalingSummary,
        );
    }

    private function buildCompressionContext(
        DerivedMetricsResult $metricsResult,
        DiagnosisResult $diagnosis,
        NormalizedMatch $normalized
    ): CompressionContext {
        return new CompressionContext(
            gameMode: $normalized->gameMode,
            memberTeam: $normalized->memberTeam,
            durationMinutes: (int) floor($normalized->duration / 60),
            lossTypeConfirmed: $diagnosis->lossTypeConfirmed,
            rootCause: $diagnosis->rootCause,
            keyFactors: $diagnosis->keyFactors,
            momentum: $diagnosis->momentum,
            memberHeroes: $metricsResult->getMemberHeroNames(),
            heroFindings: $diagnosis->heroFindings,
        );
    }
}
