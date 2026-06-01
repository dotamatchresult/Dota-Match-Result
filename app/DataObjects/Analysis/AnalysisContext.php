<?php

namespace App\DataObjects\Analysis;

readonly class AnalysisContext
{
    /**
     * @param  array<TeamfightSummary>  $keyFights
     * @param  array<PlayerImpact>  $playerImpacts
     * @param  array<string>  $momentumShifts
     * @param  array<string>  $radiantHeroes
     * @param  array<string>  $direHeroes
     */
    public function __construct(
        public string $matchId,
        public string $outcome, // 'won' | 'lost'
        public string $memberTeam,
        public int $durationMinutes,
        public string $gameMode,
        public array $keyFights,
        public array $playerImpacts,
        public array $momentumShifts,
        public array $radiantHeroes,
        public array $direHeroes,
        public ?string $criticalObjective = null,
    ) {}

    public function toArray(): array
    {
        return [
            'match_id' => $this->matchId,
            'outcome' => $this->outcome,
            'member_team' => $this->memberTeam,
            'duration' => $this->durationMinutes,
            'game_mode' => $this->gameMode,
            'radiant_heroes' => $this->radiantHeroes,
            'dire_heroes' => $this->direHeroes,
            'key_fights' => array_map(fn ($f) => [
                'time' => $f->getTimeFormatted(),
                'outcome' => $f->outcome,
                'swing' => $f->swing,
                'key_heroes' => $f->keyHeroes,
            ], $this->keyFights),
            'player_impacts' => array_map(fn ($p) => [
                'hero' => $p->heroName,
                'kda' => round($p->getKda(), 2),
                'impact_score' => round($p->impactScore, 1),
                'impact_label' => $p->impactLabel,
            ], $this->playerImpacts),
            'momentum_shifts' => $this->momentumShifts,
        ];
    }
}
