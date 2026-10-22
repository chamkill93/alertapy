<?php

namespace App\Enums;

enum TrustLevel: string
{
    case High = 'high';
    case MediumHigh = 'medium_high';
    case Medium = 'medium';
    case Low = 'low';
}
