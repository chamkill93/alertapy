<?php

namespace App\Enums;

enum IncidentStatus: string
{
    case Scheduled = 'scheduled';
    case Reported = 'reported';
    case UnderVerification = 'under_verification';
    case Confirmed = 'confirmed';
    case Active = 'active';
    case Finalized = 'finalized';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Programado',
            self::Reported => 'Reportado',
            self::UnderVerification => 'En verificación',
            self::Confirmed => 'Confirmado',
            self::Active => 'Activo',
            self::Finalized => 'Finalizado',
            self::Cancelled => 'Cancelado',
        };
    }

    public function isCurrent(): bool
    {
        return in_array($this, [
            self::Scheduled,
            self::Reported,
            self::UnderVerification,
            self::Confirmed,
            self::Active,
        ], true);
    }
}
