<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the AI provider rejects a request because the account is out of credit/quota.
 */
class InsufficientCreditException extends RuntimeException
{
    //
}
