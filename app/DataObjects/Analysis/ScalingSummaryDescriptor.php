<?php

namespace App\DataObjects\Analysis;

use App\Enums\ScalingPhase;
use App\Enums\ScalingTrendPattern;

readonly class ScalingSummaryDescriptor
{
    public function __construct(
        public ScalingPhase $earlyPhase,
        public ScalingPhase $midPhase,
        public ScalingPhase $latePhase,
        /** Minute when gold advantage first crossed from positive to negative, or null */
        public ?int $collapseMinute,
        public ScalingTrendPattern $trendPattern,
    ) {}

    /**
     * Compact payload for LLM.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'early' => $this->earlyPhase->value,
            'mid' => $this->midPhase->value,
            'late' => $this->latePhase->value,
            'collapse_at' => $this->collapseMinute,
            'pattern' => $this->trendPattern->value,
        ];
    }
}
