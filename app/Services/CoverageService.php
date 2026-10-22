<?php

namespace App\Services;

use App\Enums\SourceType;
use App\Models\IncidentSource;
use App\Models\CriticalFacility;
use Illuminate\Support\Collection;

class CoverageService
{
    /** @return Collection<int, array<string, mixed>> */
    public function departments(): Collection
    {
        $departments = collect([
            'Alto Paraguay', 'Alto Paraná', 'Amambay', 'Boquerón', 'Caaguazú', 'Caazapá', 'Canindeyú',
            'Central', 'Concepción', 'Cordillera', 'Guairá', 'Itapúa', 'Misiones', 'Ñeembucú',
            'Paraguarí', 'Presidente Hayes', 'San Pedro', 'Asunción',
        ]);
        $references = IncidentSource::query()
            ->whereHas('incident', fn ($query) => $query->whereNotNull('department')->where('published_at', '>=', now()->subDays(30)))
            ->with(['incident:id,department', 'source:id,type,configuration_json'])
            ->get()
            ->groupBy(fn (IncidentSource $reference): string => (string) $reference->incident->department);

        return $departments->map(function (string $department) use ($references): array {
            $sources = $references->get($department, collect())->pluck('source')->filter()->unique('id');
            $official = $sources->where('type', SourceType::Official)->count();
            $sensors = $sources->where('type', SourceType::Sensor)->count();
            $press = $sources->where('type', SourceType::Press);
            $regional = $press->filter(fn ($source): bool => ($source->configuration_json['collector'] ?? null) === 'regional_press')->count();
            $municipal = $sources->filter(fn ($source): bool => ($source->configuration_json['collector'] ?? null) === 'municipal')->count();
            $infrastructure = CriticalFacility::query()->where('department', $department)->count();
            $latestEvidence = $references->get($department, collect())->max(fn (IncidentSource $reference) => $reference->detected_at?->getTimestamp() ?? 0);
            $components = [
                'official' => min(100, $official * 25),
                'press' => min(100, (($press->count() - $regional) * 15) + ($regional * 30)),
                'sensors' => min(100, $sensors * 35),
                'infrastructure' => min(100, (int) round($infrastructure / 2)),
                'update' => $latestEvidence >= now()->subDay()->getTimestamp() ? 100 : ($latestEvidence >= now()->subDays(7)->getTimestamp() ? 60 : 20),
            ];
            $score = (int) round(($components['official'] * .30) + ($components['press'] * .25) + ($components['sensors'] * .15) + ($components['infrastructure'] * .15) + ($components['update'] * .15));

            return [
                'department' => $department,
                'official' => $official,
                'national_press' => $press->count() - $regional,
                'regional_press' => $regional,
                'municipality' => $municipal,
                'sensors' => $sensors,
                'total' => $sources->count(),
                'monitoring_capacity' => $score,
                'components' => $components,
                'level' => match (true) {
                    $score >= 75 => 'high',
                    $score >= 45 => 'medium',
                    default => 'low',
                },
            ];
        });
    }
}
