<?php

namespace App\DataObjects\Analysis;

use App\Enums\LossType;

readonly class LossClassification
{
    public function __construct(
        public LossType $lossType,
        /** One-sentence PHP-generated rationale, not LLM */
        public string $rationale,
        /** Signal dominance score [0..1] */
        public float $confidence,
    ) {}
}
