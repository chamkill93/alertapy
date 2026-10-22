<?php

namespace App\Enums;

enum LocationQuality: string
{
    case VeryHigh = 'very_high';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case VeryLow = 'very_low';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::VeryHigh => 'Muy alta', self::High => 'Alta', self::Medium => 'Media',
            self::Low => 'Baja', self::VeryLow => 'Muy baja', self::Unknown => 'Sin determinar',
        };
    }
}
