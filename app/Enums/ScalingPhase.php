<?php

namespace App\Enums;

enum ScalingPhase: string
{
    case Ahead = 'Ahead';
    case Even = 'Even';
    case Behind = 'Behind';
}
