<?php

namespace App\Enums;

enum Severity: string
{
    case Informational = 'informational';
    case Low = 'low';
    case Medium = 'medium';
    case Moderate = 'moderate';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Informational => 'Informativa',
            self::Low => 'Baja',
            self::Medium => 'Media',
            self::Moderate => 'Moderada',
            self::High => 'Alta',
            self::Critical => 'Crítica',
        };
    }
}
