<?php

namespace App\Enums;

enum VerificationLevel: string
{
    case Official = 'official';
    case Corroborated = 'corroborated';
    case Press = 'press';
    case Community = 'community';
    case Sensor = 'sensor';
    case Unverified = 'unverified';

    public function label(): string
    {
        return match ($this) {
            self::Official => 'Oficial', self::Corroborated => 'Corroborado', self::Press => 'Prensa',
            self::Community => 'Comunidad', self::Sensor => 'Sensor', self::Unverified => 'Sin verificar',
        };
    }
}
