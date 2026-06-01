<?php

namespace App\DataObjects\Analysis;

readonly class GatekeeperResult
{
    public function __construct(
        public bool $passed,
        public ?string $reason = null,
        public ?string $rejectCode = null,
    ) {}

    public static function pass(): self
    {
        return new self(passed: true);
    }

    public static function reject(string $reason, string $code): self
    {
        return new self(
            passed: false,
            reason: $reason,
            rejectCode: $code
        );
    }
}
