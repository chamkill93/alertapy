<?php

namespace App\Enums;

enum FreshnessStatus: string
{
    case Live = 'live';
    case Recent = 'recent';
    case Aging = 'aging';
    case Stale = 'stale';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Live => 'En vivo', self::Recent => 'Reciente', self::Aging => 'Envejeciendo',
            self::Stale => 'Desactualizado', self::Archived => 'Histórico',
        };
    }
}
