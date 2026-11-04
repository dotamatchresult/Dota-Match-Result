<?php

namespace App\Services\Analysis;

use OpenAI\Exceptions\ErrorException;
use Throwable;

/**
 * Recognises "out of credit" errors from OpenAI or OpenAI-compatible gateways
 * (OPENAI_BASE_URL may point at a different provider).
 */
class InsufficientCreditDetector
{
    public static function matches(Throwable $e): bool
    {
        if ($e instanceof ErrorException) {
            if ($e->getErrorCode() === 'insufficient_quota' || $e->getStatusCode() === 402) {
                return true;
            }
        }

        return (bool) preg_match('/insufficient|credit|quota|billing/i', $e->getMessage());
    }
}
