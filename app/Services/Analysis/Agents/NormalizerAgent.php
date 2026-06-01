<?php

namespace App\Services\Analysis\Agents;

use App\DataObjects\Analysis\NormalizedMatch;
use App\DataObjects\Analysis\NormalizedPlayer;
use App\Models\Hero;
use App\Models\Member;

class NormalizerAgent
{
    public function normalize(array $matchData, array $memberSteamIds): NormalizedMatch
    {
        $heroes = Hero::all()->keyBy('hero_id');
        $radiantPlayers = [];
        $direPlayers = [];
        $memberTeam = null;

        // Build heroIndexMap for ALL slots (0-9), including anonymous players.
        // fight['players'][$index] always corresponds to this positional mapping.
        $heroIndexMap = [];
        foreach ($matchData['players'] ?? [] as $player) {
            $slot = $player['player_slot'] ?? 0;
            $fightIndex = $slot < 128 ? $slot : ($slot - 128 + 5);
            $heroName = $heroes->get($player['hero_id'] ?? 0)?->localized_name
                ?? "Hero #{$player['hero_id']}";
            $heroIndexMap[$fightIndex] = $heroName;
        }

        foreach ($matchData['players'] ?? [] as $player) {
            $accountId = $player['account_id'] ?? null;
            if (! $accountId) {
                continue;
            }

            $steamId = Member::convertAccountIdToSteamId($accountId);
            $slot = $player['player_slot'] ?? 0;
            $isRadiant = $slot < 128;
            $team = $isRadiant ? 'radiant' : 'dire';
            $fightIndex = $isRadiant ? $slot : ($slot - 128 + 5);
            $heroName = $heroes->get($player['hero_id'] ?? 0)?->localized_name
                ?? "Hero #{$player['hero_id']}";

            $normalizedPlayer = new NormalizedPlayer(
                heroId: $player['hero_id'] ?? 0,
                heroName: $heroName,
                team: $team,
                kills: $player['kills'] ?? 0,
                deaths: $player['deaths'] ?? 0,
                assists: $player['assists'] ?? 0,
                heroDamage: $player['hero_damage'] ?? 0,
                towerDamage: $player['tower_damage'] ?? 0,
                goldPerMin: $player['gold_per_min'] ?? 0,
                xpPerMin: $player['xp_per_min'] ?? 0,
                netWorth: $player['net_worth'] ?? 0,
                playerIndex: $fightIndex,
                lastHits: $player['last_hits'] ?? 0,
                level: $player['level'] ?? 0,
                heroHealing: $player['hero_healing'] ?? 0,
                roshanKills: $player['roshan_kills'] ?? 0,
                bountyRuneCount: (int) ($player['runes']['5'] ?? 0),
            );

            if ($isRadiant) {
                $radiantPlayers[] = $normalizedPlayer;
            } else {
                $direPlayers[] = $normalizedPlayer;
            }

            if (in_array($steamId, $memberSteamIds)) {
                $memberTeam = $memberTeam ?? $team;
            }
        }

        $radiantWin = $matchData['radiant_win'] ?? false;
        $memberWon = ($memberTeam === 'radiant' && $radiantWin)
            || ($memberTeam === 'dire' && ! $radiantWin);

        return new NormalizedMatch(
            matchId: (string) ($matchData['match_id'] ?? 'unknown'),
            memberTeam: $memberTeam ?? 'unknown',
            memberWon: $memberWon,
            duration: $matchData['duration'] ?? 0,
            gameMode: $this->getGameModeName($matchData['game_mode'] ?? null),
            radiantPlayers: $radiantPlayers,
            direPlayers: $direPlayers,
            teamfights: $matchData['teamfights'] ?? [],
            objectives: $matchData['objectives'] ?? [],
            goldAdvantage: $matchData['radiant_gold_adv'] ?? [],
            xpAdvantage: $matchData['radiant_xp_adv'] ?? [],
            heroIndexMap: $heroIndexMap,
        );
    }

    private function getGameModeName(?int $gameModeId): string
    {
        return match ($gameModeId) {
            1 => 'All Pick',
            2 => 'Captains Mode',
            3 => 'Random Draft',
            4 => 'Single Draft',
            5 => 'All Random',
            16 => 'Captains Draft',
            22 => 'All Draft',
            23 => 'Turbo',
            default => 'Unknown Mode',
        };
    }
}
