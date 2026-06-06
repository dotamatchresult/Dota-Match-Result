<?php

namespace App\Support\DailyChallenge;

final class ChallengeCatalog
{
    /**
     * The canonical source of truth for all challenge definitions.
     *
     * Each entry must include all keys required by ChallengeCatalogValidator.
     *
     * @return list<array{
     *     code: string,
     *     name: string,
     *     description: string,
     *     category: string,
     *     weight: int,
     *     base_requirement: int,
     *     increment_value: int,
     *     max_requirement: int,
     *     configuration: array|null,
     *     is_active: bool
     * }>
     */
    public static function definitions(): array
    {
        $definitions = self::rawDefinitions();

        ChallengeCatalogValidator::validate($definitions);

        return $definitions;
    }

    /**
     * Raw definitions before validation.
     *
     * @return list<array<string, mixed>>
     */
    private static function rawDefinitions(): array
    {
        return [
            [
                'code' => 'total_kills',
                'name' => 'Total Kills',
                'description' => 'Get {requirement} total kills across all matches today',
                'category' => 'accumulative',
                'weight' => 15,
                'base_requirement' => 30,
                'increment_value' => 5,
                'max_requirement' => 60,
                'configuration' => null,
                'is_active' => true,
            ],
            [
                'code' => 'total_denies',
                'name' => 'Total Denies',
                'description' => 'Get {requirement} total denies across all matches today',
                'category' => 'accumulative',
                'weight' => 10,
                'base_requirement' => 20,
                'increment_value' => 5,
                'max_requirement' => 40,
                'configuration' => null,
                'is_active' => true,
            ],
            [
                'code' => 'total_heal',
                'name' => 'Total Healing',
                'description' => 'Heal {requirement} total HP across all matches today',
                'category' => 'accumulative',
                'weight' => 8,
                'base_requirement' => 10000,
                'increment_value' => 2000,
                'max_requirement' => 20000,
                'configuration' => null,
                'is_active' => true,
            ],
            [
                'code' => 'hero_win',
                'name' => 'Hero Win',
                'description' => 'Win {requirement} game(s) with the assigned hero today',
                'category' => 'accumulative',
                'weight' => 15,
                'base_requirement' => 1,
                'increment_value' => 1,
                'max_requirement' => 3,
                'configuration' => [
                    'random_hero' => true,
                    'excluded_heroes' => [
                        82, // Meepo
                    ],
                ],
                'is_active' => true,
            ],
            [
                'code' => 'item_win',
                'name' => 'Item Win',
                'description' => 'Win {requirement} game(s) where someone buys {item_name}',
                'category' => 'accumulative',
                'weight' => 10,
                'base_requirement' => 1,
                'increment_value' => 1,
                'max_requirement' => 3,
                'configuration' => null,
                'is_active' => true,
            ],
            [
                'code' => 'last_hits',
                'name' => 'Last Hits',
                'description' => 'Get {requirement} last hits in a single match',
                'category' => 'snapshot',
                'weight' => 8,
                'base_requirement' => 60,
                'increment_value' => 0,
                'max_requirement' => 60,
                'configuration' => null,
                'is_active' => true,
            ],
            [
                'code' => 'zero_death_win',
                'name' => 'Zero Death Win',
                'description' => 'Win a game with 0 deaths on any hero',
                'category' => 'snapshot',
                'weight' => 2,
                'base_requirement' => 1,
                'increment_value' => 0,
                'max_requirement' => 1,
                'configuration' => null,
                'is_active' => true,
            ],
            [
                'code' => 'fast_win',
                'name' => 'Fast Win',
                'description' => 'Win a game in under {requirement} minutes',
                'category' => 'snapshot',
                'weight' => 5,
                'base_requirement' => 25,
                'increment_value' => 0,
                'max_requirement' => 25,
                'configuration' => null,
                'is_active' => true,
            ],
        ];
    }
}
