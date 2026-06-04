<?php

namespace App\DataObjects\Challenges;

final readonly class EvaluationResult
{
    /**
     * @param  bool  $matched  Whether the match qualified against this challenge
     * @param  int  $progressDelta  How much to increment current_progress
     * @param  array<int, array{member_id: int, value: int}>  $contributors  Per-member contribution details
     * @param  array{contributors: array, matches: array, metadata: array}  $progressData  Merge-ready progress data
     * @param  bool  $completed  Whether this result completes the challenge
     */
    public function __construct(
        public bool $matched,
        public int $progressDelta = 0,
        public array $contributors = [],
        public array $progressData = [],
        public bool $completed = false,
    ) {}
}
