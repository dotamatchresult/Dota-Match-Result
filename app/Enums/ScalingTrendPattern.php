<?php

namespace App\Enums;

enum ScalingTrendPattern: string
{
    case SteadyLead = 'SteadyLead';
    case EarlyThrow = 'EarlyThrow';
    case ComebackAttempt = 'ComebackAttempt';
    case BleedOut = 'BleedOut';
    case NeverAhead = 'NeverAhead';
}
