<?php

namespace App\DataObjects\Analysis;

readonly class LLMAnalysis
{
    public function __construct(
        public string $text,
        public int $tokensUsed,
        public string $model,
        public float $temperature,
    ) {}
}
