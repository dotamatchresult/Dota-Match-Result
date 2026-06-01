<?php

namespace App\Enums;

enum LossType: string
{
    case ExecutionLoss = 'EXECUTION_LOSS';
    case Outdrafted = 'OUTDRAFTED';
    case Outscaled = 'OUTSCALED';
    case PoorConversion = 'POOR_CONVERSION';
    case PickoffCollapse = 'PICKOFF_COLLAPSE';
    case ProtectionFailure = 'PROTECTION_FAILURE';
}
