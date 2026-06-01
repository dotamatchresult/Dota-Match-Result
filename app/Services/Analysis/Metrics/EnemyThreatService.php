<?php

namespace App\Services\Analysis\Metrics;

use App\DataObjects\Analysis\EnemyThreatEntry;
use App\DataObjects\Analysis\EnemyThreatMatrix;
use App\DataObjects\Analysis\NormalizedMatch;
use App\DataObjects\Analysis\NormalizedPlayer;
use App\Enums\ImpactOnUs;
use App\Enums\ThreatType;

class EnemyThreatService
{
    public function build(NormalizedMatch $match): EnemyThreatMatrix
    {
        $enemyPlayers = $match->getEnemyTeamPlayers();

        if (empty($enemyPlayers)) {
            return new EnemyThreatMatrix([]);
        }

        $totalEnemyDamage = array_sum(array_map(fn (NormalizedPlayer $p) => $p->heroDamage, $enemyPlayers));
        $totalEnemyKills = array_sum(array_map(fn (NormalizedPlayer $p) => $p->kills, $enemyPlayers));
        $totalEnemyTowerDamage = array_sum(array_map(fn (NormalizedPlayer $p) => $p->towerDamage, $enemyPlayers));
        $enemyCount = count($enemyPlayers);
        $avgTowerDamage = $totalEnemyTowerDamage / max(1, $enemyCount);

        $entries = [];

        foreach ($enemyPlayers as $player) {
            $damageShare = $totalEnemyDamage > 0 ? $player->heroDamage / $totalEnemyDamage : 0.0;
            $killContrib = $totalEnemyKills > 0 ? ($player->kills + $player->assists) / ($totalEnemyKills * 2) : 0.0;

            $threatType = $this->classifyThreat($player, $damageShare, $killContrib, $avgTowerDamage);
            $impacts = $this->classifyImpacts($player, $threatType);

            $entries[] = new EnemyThreatEntry(
                heroName: $player->heroName,
                threatType: $threatType,
                impactOnUs: $impacts,
                damageShareEnemy: round($damageShare, 3),
                killContribution: round($killContrib, 3),
            );
        }

        usort($entries, fn (EnemyThreatEntry $a, EnemyThreatEntry $b) => $b->damageShareEnemy <=> $a->damageShareEnemy);

        return new EnemyThreatMatrix(array_slice($entries, 0, 5));
    }

    private function classifyThreat(
        NormalizedPlayer $player,
        float $damageShare,
        float $killContrib,
        float $avgTowerDamage
    ): ThreatType {
        if ($player->getKda() > 3.0 && $killContrib > 0.40) {
            return ThreatType::PickoffHunter;
        }

        if ($damageShare > 0.35) {
            return ThreatType::TeamfightCarry;
        }

        if ($killContrib > 0.35 && $damageShare < 0.25) {
            return ThreatType::InitiationThreat;
        }

        if ($avgTowerDamage > 0 && $player->towerDamage > $avgTowerDamage * 2.0) {
            return ThreatType::SplitPusher;
        }

        return ThreatType::SustainDamage;
    }

    /**
     * @return array<ImpactOnUs>
     */
    private function classifyImpacts(NormalizedPlayer $player, ThreatType $threatType): array
    {
        $impacts = [];

        if ($threatType === ThreatType::PickoffHunter) {
            $impacts[] = ImpactOnUs::PickoffPressure;
        }

        if (in_array($threatType, [ThreatType::TeamfightCarry, ThreatType::InitiationThreat], true)) {
            $impacts[] = ImpactOnUs::TeamfightDominance;
        }

        if ($player->towerDamage > 2000) {
            $impacts[] = ImpactOnUs::ObjectiveThreat;
        }

        if ($threatType === ThreatType::SplitPusher) {
            $impacts[] = ImpactOnUs::MapPressure;
        }

        // Every hero has at least one impact
        if (empty($impacts)) {
            $impacts[] = ImpactOnUs::TeamfightDominance;
        }

        // Cap at 2 impacts for token efficiency
        return array_slice($impacts, 0, 2);
    }
}
