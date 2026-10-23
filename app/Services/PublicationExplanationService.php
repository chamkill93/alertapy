<?php

namespace App\Services;

use App\Enums\SourceType;
use App\Models\Incident;

class PublicationExplanationService
{
    /** @return array{reasons: array<int, string>} */
    public function forIncident(Incident $incident): array
    {
        $reasons = [match ($incident->source_type) {
            SourceType::Official => 'Fuente oficial habilitada',
            SourceType::Sensor => 'Lectura de sensor habilitado',
            SourceType::Press => 'Fuente periodística habilitada',
            SourceType::Community => 'Reporte identificado como comunitario',
            default => 'Fuente identificada y trazable',
        }];
        if ($incident->usesReferenceLocation()) $reasons[] = 'Ubicación no informada; marcador de referencia en Asunción';
        elseif ($incident->latitude !== null && $incident->longitude !== null) $reasons[] = 'Ubicación territorial identificada';
        if ($incident->published_at !== null) $reasons[] = 'Publicación con fecha verificable';
        if ($incident->sources()->distinct('source_id')->count('source_id') >= 2) $reasons[] = 'Coincide con una fuente independiente';
        return ['reasons' => $reasons];
    }
}
