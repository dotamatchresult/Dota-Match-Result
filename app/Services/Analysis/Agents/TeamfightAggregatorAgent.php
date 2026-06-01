<?php

namespace App\Services\Analysis\Agents;

use App\DataObjects\Analysis\NormalizedMatch;
use App\DataObjects\Analysis\TeamfightSummary;

class TeamfightAggregatorAgent
{
    /**
     * @return array<TeamfightSummary>
     */
    public function aggregate(NormalizedMatch $match): array
    {
        $summaries = [];

        foreach ($match->teamfights as $index => $fight) {
            $direKills = 0;
            $radiantKills = 0;
            $direGoldDelta = 0;
            $radiantGoldDelta = 0;
            $damageDealt = [];

            foreach ($fight['players'] ?? [] as $playerIndex => $playerFight) {
                $isRadiant = $playerIndex < 5;
                $deaths = $playerFight['deaths'] ?? 0;
                $goldDelta = $playerFight['gold_delta'] ?? 0;
                $damage = $playerFight['damage'] ?? 0;

                if ($isRadiant) {
                    $radiantKills += $deaths;
                    $radiantGoldDelta += $goldDelta;
                } else {
                    $direKills += $deaths;
                    $direGoldDelta += $goldDelta;
                }

                $damageDealt[$playerIndex] = $damage;
            }

            // Identify key heroes (top damage dealers)
            arsort($damageDealt);
            $topDamagers = array_slice(array_keys($damageDealt), 0, 3, true);
            $keyHeroes = [];

            foreach ($topDamagers as $playerIndex) {
                if ($damageDealt[$playerIndex] > 1000) {
                    $heroName = $this->getHeroNameByIndex($match, $playerIndex);
                    if ($heroName) {
                        $keyHeroes[] = $heroName;
                    }
                }
            }

            $outcome = $this->determineOutcome($direKills, $radiantKills, $direGoldDelta, $radiantGoldDelta);
            $swing = $this->classifySwing($direGoldDelta, $radiantGoldDelta);

            $summaries[] = new TeamfightSummary(
                index: $index,
                startTime: $fight['start'] ?? 0,
                endTime: $fight['end'] ?? 0,
                duration: ($fight['end'] ?? 0) - ($fight['start'] ?? 0),
                outcome: $outcome,
                swing: $swing,
                direKills: $direKills,
                radiantKills: $radiantKills,
                direGoldDelta: $direGoldDelta,
                radiantGoldDelta: $radiantGoldDelta,
                keyHeroes: array_unique($keyHeroes),
            );
        }

        return $summaries;
    }

    private function determineOutcome(int $direKills, int $radiantKills, int $direGold, int $radiantGold): string
    {
        // Dire won the fight if they got more kills and positive gold
        if ($direKills > $radiantKills + 1 && $direGold > 500) {
            return 'dire_win';
        }

        // Radiant won the fight
        if ($radiantKills > $direKills + 1 && $radiantGold > 500) {
            return 'radiant_win';
        }

        return 'even';
    }

    private function classifySwing(int $direGold, int $radiantGold): string
    {
        $delta = abs($direGold - $radiantGold);

        if ($delta > 2000) {
            return 'big';
        }

        if ($delta > 1000) {
            return 'medium';
        }

        return 'small';
    }

    private function getHeroNameByIndex(NormalizedMatch $match, int $index): ?string
    {
        // Use the heroIndexMap built by NormalizerAgent for all 10 positions,
        // including anonymous players. This avoids the off-by-one bug that occurred
        // when anonymous players were skipped during normalization.
        return $match->heroIndexMap[$index] ?? null;
    }
}
