<?php

namespace App\Services;

use App\Enums\SourceType;
use App\Enums\VerificationLevel;
use App\Models\Incident;

class IncidentVerificationService
{
    public function forIncident(Incident $incident): VerificationLevel
    {
        $independent = $incident->relationLoaded('sources') ? $incident->sources->unique('source_id')->count() : $incident->sources()->distinct('source_id')->count('source_id');
        if ($independent >= 2 || $incident->verification_level === 'corroborated') return VerificationLevel::Corroborated;
        return match ($incident->source_type) {
            SourceType::Official => VerificationLevel::Official,
            SourceType::Sensor => VerificationLevel::Sensor,
            SourceType::Press => VerificationLevel::Press,
            SourceType::Community => VerificationLevel::Community,
            default => VerificationLevel::Unverified,
        };
    }
}
