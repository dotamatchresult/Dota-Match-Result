<?php

namespace App\DataObjects\Challenges;

final readonly class ReviewDecision
{
    /**
     * @param  bool  $allowed  Whether the review can proceed normally
     * @param  bool  $blocked  Whether the review is blocked by unresolved matches
     * @param  bool  $forceReview  Whether the review should be forced despite blocking matches
     * @param  array<int>  $blockingMatches  Array of match IDs that are blocking review
     */
    public function __construct(
        public bool $allowed,
        public bool $blocked,
        public bool $forceReview,
        public array $blockingMatches = [],
    ) {}

    /**
     * Review may proceed normally — no blocking matches.
     */
    public static function allowed(): self
    {
        return new self(allowed: true, blocked: false, forceReview: false);
    }

    /**
     * Review is blocked by unresolved matches.
     *
     * @param  array<int>  $matchIds
     */
    public static function blocked(array $matchIds): self
    {
        return new self(allowed: false, blocked: true, forceReview: false, blockingMatches: $matchIds);
    }

    /**
     * Review should be forced despite blocking matches (threshold exceeded).
     *
     * @param  array<int>  $matchIds
     */
    public static function forceReview(array $matchIds): self
    {
        return new self(allowed: true, blocked: false, forceReview: true, blockingMatches: $matchIds);
    }
}
