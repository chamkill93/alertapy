<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\TerritorialProfile;
use Illuminate\Support\Str;

class TerritorialImpactService
{
    /** @return array<string, mixed>|null */
    public function forIncident(Incident $incident): ?array
    {
        $territory = $incident->district ?: $incident->city;

        if (blank($territory)) {
            return null;
        }

        $normalizedDepartment = $this->normalizeDepartment((string) $incident->department);
        $normalizedTerritory = $this->normalize($territory);

        $profile = TerritorialProfile::query()
            ->where('normalized_district', $normalizedTerritory)
            ->when(
                filled($normalizedDepartment),
                fn ($query) => $query->where('normalized_department', $normalizedDepartment),
            )
            ->first();

        if ($profile === null) {
            return null;
        }

        return [
            'level' => $profile->level,
            'territory' => [
                'department' => $profile->department,
                'district' => $profile->district,
            ],
            'population_reference' => $profile->population,
            'census_year' => $profile->census_year,
            'location_basis' => $incident->location_accuracy->value,
            'disclaimer' => 'Población censal del distrito de referencia; no equivale a personas afectadas.',
            'source' => [
                'name' => $profile->source_name,
                'url' => $profile->source_url,
                'synced_at' => $profile->synced_at?->toIso8601String(),
            ],
        ];
    }

    public function normalize(string $value): string
    {
        $normalized = Str::lower(Str::ascii(trim($value)));

        return preg_replace('/\s+/u', ' ', $normalized) ?: $normalized;
    }

    private function normalizeDepartment(string $department): string
    {
        $normalized = $this->normalize($department);

        return $normalized === 'capital' ? 'asuncion' : $normalized;
    }
}
