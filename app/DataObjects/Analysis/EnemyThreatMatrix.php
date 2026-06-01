<?php

namespace App\DataObjects\Analysis;

readonly class EnemyThreatMatrix
{
    /**
     * @param  array<EnemyThreatEntry>  $entries  Sorted by damageShareEnemy desc, max 5
     */
    public function __construct(
        public array $entries,
    ) {}

    public function primaryThreat(): string
    {
        return $this->entries[0]->heroName ?? 'unknown';
    }

    /**
     * Compact payload for LLM — no raw floats sent.
     *
     * @return array<array{hero: string, threat_type: string, impact_on_us: string[]}>
     */
    public function toPayload(): array
    {
        return array_map(fn (EnemyThreatEntry $e) => [
            'hero' => $e->heroName,
            'threat_type' => $e->threatType->value,
            'impact_on_us' => array_map(fn ($i) => $i->value, $e->impactOnUs),
        ], $this->entries);
    }
}
