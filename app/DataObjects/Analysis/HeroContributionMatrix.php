<?php

namespace App\DataObjects\Analysis;

readonly class HeroContributionMatrix
{
    /**
     * @param  array<HeroContributionEntry>  $entries  Member team heroes only, sorted by damage share desc, max 5
     */
    public function __construct(
        public array $entries,
    ) {}

    public function getByHero(string $heroName): ?HeroContributionEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->heroName === $heroName) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Serialize to a compact array for LLM payload — tags only, no raw floats.
     *
     * @return array<array{hero: string, role: string[], tags: string[]}>
     */
    public function toPayload(): array
    {
        return array_map(fn (HeroContributionEntry $e) => [
            'hero' => $e->heroName,
            'role' => $e->inferredRole,
            'tags' => $e->heroTags,
        ], $this->entries);
    }

    /**
     * Serialize for storage in analysis_data JSON.
     *
     * @return array<array{hero: string, role: string[], damage_share: float, tags: string[]}>
     */
    public function toStorable(): array
    {
        return array_map(fn (HeroContributionEntry $e) => [
            'hero' => $e->heroName,
            'role' => $e->inferredRole,
            'damage_share' => $e->damageShare,
            'fight_participation' => $e->fightParticipation,
            'kill_participation' => $e->killParticipation,
            'pre_fight_death_rate' => $e->preFightDeathRate,
            'objective_participation' => $e->objectiveParticipation,
            'tags' => $e->heroTags,
        ], $this->entries);
    }
}
