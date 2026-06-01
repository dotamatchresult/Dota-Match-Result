<?php

namespace App\Enums;

enum ThreatType: string
{
    case PickoffHunter = 'pickoff_hunter';
    case TeamfightCarry = 'teamfight_carry';
    case InitiationThreat = 'initiation_threat';
    case SplitPusher = 'split_pusher';
    case SustainDamage = 'sustain_damage';
}
