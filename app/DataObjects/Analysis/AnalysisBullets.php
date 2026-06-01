<?php

namespace App\DataObjects\Analysis;

readonly class AnalysisBullets
{
    public function __construct(
        public string $bulletText,
        public int $tokensUsed,
        public string $model,
    ) {}
}
