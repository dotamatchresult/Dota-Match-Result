<?php

namespace App\Services\ChallengeEvaluators;

use App\Contracts\Challenges\ChallengeEvaluator;

class ChallengeEvaluatorRegistry
{
    /** @var array<string, class-string<ChallengeEvaluator>|string> */
    protected array $evaluators;

    public function __construct()
    {
        $this->evaluators = config('dota.daily_challenge.evaluators', []);
    }

    /**
     * Resolve an evaluator implementation for the given challenge code.
     *
     * Returns null if no evaluator is registered or the registered class doesn't exist.
     */
    public function resolve(string $code): ?ChallengeEvaluator
    {
        $class = $this->evaluators[$code] ?? null;

        if ($class === null || ! is_string($class) || $class === $code) {
            return null;
        }

        if (! class_exists($class)) {
            return null;
        }

        $instance = app($class);

        if (! $instance instanceof ChallengeEvaluator) {
            return null;
        }

        return $instance;
    }
}
