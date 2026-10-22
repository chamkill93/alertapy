<?php

namespace App\Enums;

enum LifecycleStatus: string
{
    case Detected = 'detected';
    case Active = 'active';
    case Monitoring = 'monitoring';
    case Resolved = 'resolved';
    case Expired = 'expired';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Detected => 'Detectado', self::Active => 'Activo', self::Monitoring => 'En seguimiento',
            self::Resolved => 'Resuelto', self::Expired => 'Vencido', self::Discarded => 'Descartado',
        };
    }
}
