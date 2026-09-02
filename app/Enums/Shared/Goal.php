<?php

namespace App\Enums\Shared;

enum Goal: string
{
    case Hypertrophy = 'hypertrophy';
    case Strength = 'strength';
    case FatLoss = 'fat_loss';
    case GeneralHealth = 'general_health';
    case Endurance = 'endurance';
}
