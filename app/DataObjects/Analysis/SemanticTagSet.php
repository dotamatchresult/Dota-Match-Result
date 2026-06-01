<?php

namespace App\DataObjects\Analysis;

readonly class SemanticTagSet
{
    /**
     * @param  array<string>  $activeTags
     */
    public function __construct(
        public array $activeTags,
    ) {}

    public function has(string $tag): bool
    {
        return in_array($tag, $this->activeTags, true);
    }
}
