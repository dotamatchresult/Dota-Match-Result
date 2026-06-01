<?php

namespace App\Enums;

enum ImpactOnUs: string
{
    case PickoffPressure = 'pickoff_pressure';
    case TeamfightDominance = 'teamfight_dominance';
    case ObjectiveThreat = 'objective_threat';
    case MapPressure = 'map_pressure';
}
