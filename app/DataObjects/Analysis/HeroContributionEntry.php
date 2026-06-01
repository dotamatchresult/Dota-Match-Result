<?php

namespace App\DataObjects\Analysis;

readonly class HeroContributionEntry
{
    /**
     * @param  array<string>  $heroTags  Derived deterministic tags for this hero
     */
    public function __construct(
        public string $heroName,
        /** @var array<string> Inferred roles: any of primary_damage | carry_farming | initiator | support_control | utility */
        public array $inferredRole,
        /** Hero's share of total member team hero damage (0–1) */
        public float $damageShare,
        /** Fraction of fights this hero participated in (0–1) */
        public float $fightParticipation,
        /** (kills + assists) / max(1, total team kills) (0–1) */
        public float $killParticipation,
        /** Deaths outside fight windows / total deaths (0–1) */
        public float $preFightDeathRate,
        /** Weighted composite: tower damage + Roshan kills (core) + bounty runes (0–1) */
        public float $objectiveParticipation,
        public array $heroTags,
    ) {}

    public function hasTags(): bool
    {
        return ! empty($this->heroTags);
    }
}
