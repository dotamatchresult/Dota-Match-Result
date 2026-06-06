<?php

namespace App\Support\DailyChallenge;

use InvalidArgumentException;

final class ChallengeCatalogValidator
{
    /**
     * The required keys every catalog entry must have.
     *
     * @var list<string>
     */
    private const REQUIRED_KEYS = [
        'code',
        'name',
        'description',
        'weight',
        'base_requirement',
        'increment_value',
        'max_requirement',
    ];

    /**
     * Validate an array of catalog definitions.
     *
     * @param  list<array<string, mixed>>  $definitions
     *
     * @throws InvalidArgumentException
     */
    public static function validate(array $definitions): void
    {
        $codesSeen = [];

        foreach ($definitions as $index => $definition) {
            $code = $definition['code'] ?? "(index {$index})";

            // --- Required keys ---
            foreach (self::REQUIRED_KEYS as $key) {
                if (! array_key_exists($key, $definition)) {
                    throw new InvalidArgumentException(
                        "Invalid catalog entry '{$code}': missing required key '{$key}'."
                    );
                }
            }

            // --- Weight >= 1 ---
            if ((int) $definition['weight'] < 1) {
                throw new InvalidArgumentException(
                    "Invalid catalog entry '{$code}': weight must be >= 1, got {$definition['weight']}."
                );
            }

            // --- base_requirement <= max_requirement ---
            if ((int) $definition['base_requirement'] > (int) $definition['max_requirement']) {
                throw new InvalidArgumentException(
                    "Invalid catalog entry '{$code}': base_requirement ({$definition['base_requirement']}) must be <= max_requirement ({$definition['max_requirement']})."
                );
            }

            // --- Unique codes ---
            if (in_array($code, $codesSeen, true)) {
                throw new InvalidArgumentException(
                    "Invalid catalog entry '{$code}': duplicate code detected."
                );
            }

            $codesSeen[] = $code;
        }
    }
}
