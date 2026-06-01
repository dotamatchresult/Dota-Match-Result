<?php

namespace App\Services\Analysis\Metrics;

use App\DataObjects\Analysis\DerivedMetrics;
use App\DataObjects\Analysis\NormalizedMatch;
use App\DataObjects\Analysis\ObjectiveFlow;
use App\DataObjects\Analysis\PlayerImpact;
use App\DataObjects\Analysis\TeamfightSummary;

class MetricEngineService
{
    /**
     * @param  array<TeamfightSummary>  $teamfightSummaries
     * @param  array<PlayerImpact>  $playerImpacts
     */
    public function compute(
        NormalizedMatch $match,
        array $teamfightSummaries,
        ObjectiveFlow $objectiveFlow,
        array $playerImpacts
    ): DerivedMetrics {
        return new DerivedMetrics(
            fightControlRatio: $this->computeFightControlRatio($match, $teamfightSummaries),
            objectiveConversionRate: $this->computeObjectiveConversionRate($match, $teamfightSummaries, $objectiveFlow),
            enemyPickoffRate: $this->computeEnemyPickoffRate($match),
            protectionIndex: $this->computeProtectionIndex($match),
            damageConcentrationRatio: $this->computeDamageConcentrationRatio($playerImpacts),
            scalingEarlyGold: $this->computeScalingSegment($match->goldAdvantage, 0, 14, $match->memberTeam),
            scalingMidGold: $this->computeScalingSegment($match->goldAdvantage, 15, 29, $match->memberTeam),
            scalingLateGold: $this->computeScalingSegment($match->goldAdvantage, 30, null, $match->memberTeam),
        );
    }

    /** @param  array<TeamfightSummary>  $teamfightSummaries */
    private function computeFightControlRatio(NormalizedMatch $match, array $teamfightSummaries): float
    {
        if (empty($teamfightSummaries)) {
            return 0.5;
        }

        $winOutcome = $match->memberTeam === 'radiant' ? 'radiant_win' : 'dire_win';
        $wins = count(array_filter($teamfightSummaries, fn ($f) => $f->outcome === $winOutcome));

        return round($wins / count($teamfightSummaries), 3);
    }

    /** @param  array<TeamfightSummary>  $teamfightSummaries */
    private function computeObjectiveConversionRate(
        NormalizedMatch $match,
        array $teamfightSummaries,
        ObjectiveFlow $objectiveFlow
    ): float {
        if (empty($teamfightSummaries)) {
            return 0.0;
        }

        $winOutcome = $match->memberTeam === 'radiant' ? 'radiant_win' : 'dire_win';
        $winIndexes = array_map(
            fn ($f) => $f->index,
            array_filter($teamfightSummaries, fn ($f) => $f->outcome === $winOutcome)
        );
        $totalFightWins = count($winIndexes);

        if ($totalFightWins === 0) {
            return 0.0;
        }

        // Count objective events (taken by member team) linked to a winning fight
        $conversions = 0;
        foreach ($objectiveFlow->events as $event) {
            if ($event->team !== $match->memberTeam) {
                continue;
            }
            if ($event->linkedFightIndex !== null && in_array($event->linkedFightIndex, $winIndexes, true)) {
                $conversions++;
            }
        }

        return round(min(1.0, $conversions / $totalFightWins), 3);
    }

    /**
     * Fraction of member deaths that occurred outside all teamfight windows.
     * High value = enemy had high pickoff rate against us.
     */
    private function computeEnemyPickoffRate(NormalizedMatch $match): float
    {
        $memberPlayers = $match->getMemberTeamPlayers();
        $totalMemberDeaths = (int) array_sum(array_map(fn ($p) => $p->deaths, $memberPlayers));

        if ($totalMemberDeaths === 0) {
            return 0.0;
        }

        $deathsInsideFights = 0;
        foreach ($memberPlayers as $player) {
            foreach ($match->teamfights as $fight) {
                $deathsInsideFights += $fight['players'][$player->playerIndex]['deaths'] ?? 0;
            }
        }

        $pickoffDeaths = max(0, $totalMemberDeaths - $deathsInsideFights);

        return round($pickoffDeaths / $totalMemberDeaths, 3);
    }

    /**
     * How well the core (highest net-worth member player) was protected before minute 20.
     * 1.0 = all core deaths happened after min 20. 0.0 = all before.
     */
    private function computeProtectionIndex(NormalizedMatch $match): float
    {
        $memberPlayers = $match->getMemberTeamPlayers();

        if (empty($memberPlayers)) {
            return 1.0;
        }

        $corePlayer = array_reduce(
            $memberPlayers,
            fn ($carry, $p) => $carry === null || $p->netWorth > $carry->netWorth ? $p : $carry
        );

        if ($corePlayer === null || $corePlayer->deaths === 0) {
            return 1.0;
        }

        $earlyDeaths = 0;
        foreach ($match->teamfights as $fight) {
            if (($fight['start'] ?? 0) >= 1200) {
                continue;
            }
            $earlyDeaths += $fight['players'][$corePlayer->playerIndex]['deaths'] ?? 0;
        }

        return round(max(0.0, 1.0 - ($earlyDeaths / $corePlayer->deaths)), 3);
    }

    /**
     * How concentrated is the team's hero damage in one player.
     * 1.0 = all damage from one player; ~0.2 = perfectly even across 5.
     *
     * @param  array<PlayerImpact>  $playerImpacts
     */
    private function computeDamageConcentrationRatio(array $playerImpacts): float
    {
        if (empty($playerImpacts)) {
            return 0.0;
        }

        $damages = array_map(fn ($p) => $p->heroDamage, $playerImpacts);
        $totalDamage = (int) array_sum($damages);

        if ($totalDamage === 0) {
            return 0.0;
        }

        return round(max($damages) / $totalDamage, 3);
    }

    /**
     * Average gold advantage for member team in a time window.
     * `radiant_gold_adv` is always radiant-perspective; invert for dire teams.
     */
    private function computeScalingSegment(array $goldAdvantage, int $from, ?int $to, string $memberTeam): int
    {
        if (empty($goldAdvantage)) {
            return 0;
        }

        $length = $to !== null ? ($to - $from + 1) : null;
        $slice = array_slice($goldAdvantage, $from, $length);

        if (empty($slice)) {
            return 0;
        }

        $avg = (int) round(array_sum($slice) / count($slice));

        return $memberTeam === 'dire' ? -$avg : $avg;
    }
}
