<?php

namespace App\Services\Analysis\Agents;

use App\DataObjects\Analysis\NormalizedMatch;
use App\DataObjects\Analysis\NormalizedPlayer;
use App\DataObjects\Analysis\PlayerImpact;
use App\DataObjects\Analysis\TeamfightSummary;

class PlayerImpactAgent
{
    /**
     * @param  array<TeamfightSummary>  $teamfightSummaries
     * @return array<PlayerImpact>
     */
    public function calculate(NormalizedMatch $match, array $teamfightSummaries): array
    {
        $impacts = [];
        $losingOutcome = $match->memberTeam === 'radiant' ? 'dire_win' : 'radiant_win';

        foreach ($match->getMemberTeamPlayers() as $player) {
            // Accurate death counts from actual per-fight player data
            $deathsInAllFights = $this->countDeathsInFightWindows($player, $match->teamfights);
            $deathsInLosingFights = $this->countDeathsInLosingFights($player, $match->teamfights, $teamfightSummaries, $losingOutcome);

            $fightParticipation = count($teamfightSummaries) > 0
                ? min(1.0, $deathsInAllFights / max(1, count($teamfightSummaries)))
                : 0.0;

            $impactScore = $this->calculateImpactScore($player, $deathsInLosingFights);
            $impactLabel = $this->labelImpact($impactScore);

            $impacts[] = new PlayerImpact(
                heroName: $player->heroName,
                kills: $player->kills,
                deaths: $player->deaths,
                assists: $player->assists,
                heroDamage: $player->heroDamage,
                towerDamage: $player->towerDamage,
                impactScore: $impactScore,
                impactLabel: $impactLabel,
                fightParticipation: $fightParticipation,
                deathsInLosingFights: $deathsInLosingFights,
                deathsInAllFights: $deathsInAllFights,
                netWorth: $player->netWorth,
            );
        }

        usort($impacts, fn ($a, $b) => $b->impactScore <=> $a->impactScore);

        return $impacts;
    }

    /**
     * Count deaths for a player across all teamfight windows using actual fight data.
     */
    private function countDeathsInFightWindows(NormalizedPlayer $player, array $rawFights): int
    {
        $total = 0;
        foreach ($rawFights as $fight) {
            $total += $fight['players'][$player->playerIndex]['deaths'] ?? 0;
        }

        return $total;
    }

    /**
     * Count deaths specifically in fights the member team lost.
     *
     * @param  array<TeamfightSummary>  $teamfightSummaries
     */
    private function countDeathsInLosingFights(NormalizedPlayer $player, array $rawFights, array $teamfightSummaries, string $losingOutcome): int
    {
        $total = 0;
        foreach ($teamfightSummaries as $index => $summary) {
            if ($summary->outcome !== $losingOutcome) {
                continue;
            }
            $total += $rawFights[$summary->index]['players'][$player->playerIndex]['deaths'] ?? 0;
        }

        return $total;
    }

    private function calculateImpactScore($player, int $deathsInLosingFights): float
    {
        $score = 0.0;

        // Positive contributions
        $score += $player->kills * 2;
        $score += $player->assists;
        $score += $player->heroDamage / 500;
        $score += $player->towerDamage / 1000;

        // Negative factors
        $score -= $player->deaths * 1.5;
        $score -= $deathsInLosingFights * 0.5;

        return round($score, 2);
    }

    private function labelImpact(float $score): string
    {
        if ($score >= 20) {
            return 'high';
        }

        if ($score >= 10) {
            return 'medium';
        }

        return 'low';
    }
}
