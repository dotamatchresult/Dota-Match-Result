<?php

namespace App\Services\Analysis\Metrics;

use App\DataObjects\Analysis\HeroContributionEntry;
use App\DataObjects\Analysis\HeroContributionMatrix;
use App\DataObjects\Analysis\NormalizedMatch;
use App\DataObjects\Analysis\NormalizedPlayer;
use App\DataObjects\Analysis\PlayerImpact;
use App\DataObjects\Analysis\TeamfightSummary;

class HeroContributionService
{
    /**
     * Build the Hero Contribution Matrix for member team players.
     *
     * @param  array<PlayerImpact>  $playerImpacts
     * @param  array<TeamfightSummary>  $teamfightSummaries
     */
    public function build(
        NormalizedMatch $match,
        array $playerImpacts,
        array $teamfightSummaries
    ): HeroContributionMatrix {
        $memberPlayers = $match->getMemberTeamPlayers();

        // Build lookup: heroName → PlayerImpact
        $impactMap = [];
        foreach ($playerImpacts as $impact) {
            $impactMap[$impact->heroName] = $impact;
        }

        // Team-level totals for normalization
        $totalTeamDamage = max(1, array_sum(array_map(fn (NormalizedPlayer $p) => $p->heroDamage, $memberPlayers)));
        $totalTeamTowerDamage = max(1, array_sum(array_map(fn (NormalizedPlayer $p) => $p->towerDamage, $memberPlayers)));
        $totalTeamKills = max(1, array_sum(array_map(fn (NormalizedPlayer $p) => $p->kills, $memberPlayers)));
        $totalTeamRoshanKills = array_sum(array_map(fn (NormalizedPlayer $p) => $p->roshanKills, $memberPlayers));
        $totalTeamBountyRunes = max(1, array_sum(array_map(fn (NormalizedPlayer $p) => $p->bountyRuneCount, $memberPlayers)));

        // Determine netWorth ranks for role inference
        $sortedByNetWorth = $memberPlayers;
        usort($sortedByNetWorth, fn (NormalizedPlayer $a, NormalizedPlayer $b) => $b->netWorth <=> $a->netWorth);
        $highestNetWorthHero = $sortedByNetWorth[0]->heroName ?? null;
        $bottomNetWorthNames = array_map(
            fn (NormalizedPlayer $p) => $p->heroName,
            array_slice($sortedByNetWorth, -2)
        );

        $entries = [];
        foreach ($memberPlayers as $player) {
            $impact = $impactMap[$player->heroName] ?? null;
            if (! $impact) {
                continue;
            }

            $damageShare = $player->heroDamage / $totalTeamDamage;
            $killParticipation = ($player->kills + $player->assists) / $totalTeamKills;
            $preFightDeathRate = $this->computePreFightDeathRate($impact);

            $isHighestNetWorth = $player->heroName === $highestNetWorthHero;
            $isBottomNetWorth = in_array($player->heroName, $bottomNetWorthNames, true);

            $inferredRoles = $this->inferRole(
                player: $player,
                impact: $impact,
                damageShare: $damageShare,
                isHighestNetWorth: $isHighestNetWorth,
                isBottomNetWorth: $isBottomNetWorth
            );

            $objectiveParticipation = $this->computeObjectiveParticipation(
                player: $player,
                inferredRoles: $inferredRoles,
                totalTeamTowerDamage: $totalTeamTowerDamage,
                totalTeamRoshanKills: $totalTeamRoshanKills,
                totalTeamBountyRunes: $totalTeamBountyRunes,
            );

            $heroTags = $this->computeTags(
                inferredRoles: $inferredRoles,
                damageShare: $damageShare,
                preFightDeathRate: $preFightDeathRate,
                fightParticipation: $impact->fightParticipation,
                objectiveParticipation: $objectiveParticipation,
                deaths: $impact->deaths,
                deathsInLosingFights: $impact->deathsInLosingFights,
                deathsInAllFights: $impact->deathsInAllFights,
            );

            $entries[] = new HeroContributionEntry(
                heroName: $player->heroName,
                inferredRole: $inferredRoles,
                damageShare: round($damageShare, 3),
                fightParticipation: round($impact->fightParticipation, 3),
                killParticipation: round($killParticipation, 3),
                preFightDeathRate: round($preFightDeathRate, 3),
                objectiveParticipation: round($objectiveParticipation, 3),
                heroTags: $heroTags,
            );
        }

        // Sort by damage share descending, limit to 5 for LLM token efficiency
        usort($entries, fn (HeroContributionEntry $a, HeroContributionEntry $b) => $b->damageShare <=> $a->damageShare);
        $entries = array_slice($entries, 0, 5);

        return new HeroContributionMatrix(entries: $entries);
    }

    /**
     * Infer all applicable hero roles from relative positioning within the team.
     * Multiple roles are possible; 'utility' is used only when no other role matches.
     *
     * @return array<string>
     */
    private function inferRole(
        NormalizedPlayer $player,
        PlayerImpact $impact,
        float $damageShare,
        bool $isHighestNetWorth,
        bool $isBottomNetWorth
    ): array {
        $roles = [];

        if ($damageShare > 0.30) {
            $roles[] = 'primary_damage';
        }

        if ($isHighestNetWorth) {
            $roles[] = 'carry_farming';
        }

        if ($isBottomNetWorth) {
            $assistRatio = ($impact->kills + $impact->assists) > 0
                ? $impact->assists / ($impact->kills + $impact->assists)
                : 0.0;

            if ($assistRatio > 0.60) {
                $roles[] = 'support_control';
            }
        }

        if ($impact->fightParticipation > 0.75) {
            $roles[] = 'initiator';
        }

        if (empty($roles)) {
            $roles[] = 'utility';
        }

        return $roles;
    }

    /**
     * Compute a composite objective participation score (0–1) from:
     * - Tower/building damage (all roles, weight 0.50 / 0.60)
     * - Roshan kills (core roles only, weight 0.30; redistributed if no Roshan in match)
     * - Bounty rune pickups (all roles, weight 0.20 / 0.40)
     *
     * @param  array<string>  $inferredRoles
     */
    private function computeObjectiveParticipation(
        NormalizedPlayer $player,
        array $inferredRoles,
        int $totalTeamTowerDamage,
        int $totalTeamRoshanKills,
        int $totalTeamBountyRunes,
    ): float {
        $towerShare = $player->towerDamage / max(1, $totalTeamTowerDamage);
        $bountyShare = $player->bountyRuneCount / $totalTeamBountyRunes;

        $isCoreRole = ! empty(array_intersect($inferredRoles, ['primary_damage', 'carry_farming']));

        if ($isCoreRole && $totalTeamRoshanKills > 0) {
            $roshanShare = $player->roshanKills / $totalTeamRoshanKills;

            return 0.50 * $towerShare + 0.30 * $roshanShare + 0.20 * $bountyShare;
        }

        return 0.60 * $towerShare + 0.40 * $bountyShare;
    }

    /**
     * Fraction of deaths that occurred outside fight windows.
     * A high value indicates the hero is being caught out / picked off.
     */
    private function computePreFightDeathRate(PlayerImpact $impact): float
    {
        if ($impact->deaths === 0) {
            return 0.0;
        }

        $outsideFightDeaths = max(0, $impact->deaths - $impact->deathsInAllFights);

        return $outsideFightDeaths / $impact->deaths;
    }

    /**
     * Derive deterministic boolean tags for a hero.
     *
     * @param  array<string>  $inferredRoles
     * @return array<string>
     */
    private function computeTags(
        array $inferredRoles,
        float $damageShare,
        float $preFightDeathRate,
        float $fightParticipation,
        float $objectiveParticipation,
        int $deaths,
        int $deathsInLosingFights,
        int $deathsInAllFights,
    ): array {
        $tags = [];

        // Damage output is dangerously concentrated on this hero
        if ($damageShare > 0.40) {
            $tags[] = 'damage_dependency_core';
        }

        // Hero dies frequently before or outside of fights
        if ($preFightDeathRate > 0.45) {
            $tags[] = 'frequent_first_death';
        }

        // Core hero (damage dealer / farmer) dies disproportionately in fights the team loses
        $isCoreRole = ! empty(array_intersect($inferredRoles, ['primary_damage', 'carry_farming']));
        if ($isCoreRole && $deathsInAllFights > 0 && ($deathsInLosingFights / $deathsInAllFights) > 0.60) {
            $tags[] = 'core_under_protected';
        }

        // Initiator hero has low fight participation — not showing up when needed
        if (in_array('initiator', $inferredRoles, true) && $fightParticipation < 0.50) {
            $tags[] = 'ineffective_initiation';
        }

        // Hero is caught alone / picked off repeatedly (separate from frequent_first_death)
        if ($preFightDeathRate > 0.35 && $deaths > 3) {
            $tags[] = 'frequent_pickoff_victim';
        }

        // Non-support hero contributes little to objectives
        if (! in_array('support_control', $inferredRoles, true) && $objectiveParticipation < 0.20) {
            $tags[] = 'low_objective_presence';
        }

        return $tags;
    }
}
