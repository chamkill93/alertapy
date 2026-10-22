<?php

namespace App\Enums;

enum SourceType: string
{
    case Official = 'official';
    case Press = 'press';
    case Sensor = 'sensor';
    case Social = 'social';
    case Discovery = 'discovery';
    case Community = 'community';

    public function label(): string
    {
        return match ($this) {
            self::Official => 'Oficial',
            self::Press => 'Prensa',
            self::Sensor => 'Sensor',
            self::Social => 'Social',
            self::Discovery => 'Discovery',
            self::Community => 'Comunidad',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Official => '🏛',
            self::Press => '📰',
            self::Sensor => '📡',
            self::Social => '💬',
            self::Discovery => '🔎',
            self::Community => '👥',
        };
    }
}
