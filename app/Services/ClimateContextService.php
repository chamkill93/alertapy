<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\MonitoringStation;
use Illuminate\Support\Collection;

class ClimateContextService
{
    public function __construct(private readonly GeoDistanceService $distance) {}

    /** @return array<string, mixed>|null */
    public function forIncident(Incident $incident): ?array
    {
        if ($incident->latitude === null || $incident->longitude === null || $incident->published_at === null) return null;
        $box = $this->distance->boundingBox($incident->latitude, $incident->longitude, 30);
        $candidates = Incident::query()->published()
            ->where('id', '!=', $incident->id)
            ->whereBetween('published_at', [$incident->published_at->copy()->subHours(12), $incident->published_at->copy()->addHours(12)])
            ->whereBetween('latitude', [$box['south'], $box['north']])->whereBetween('longitude', [$box['west'], $box['east']])
            ->with('category:id,slug,name,icon')->get()
            ->map(function (Incident $candidate) use ($incident): Incident {
                $candidate->setAttribute('distance_km', $this->distance->kilometers($incident->latitude, $incident->longitude, $candidate->latitude, $candidate->longitude));
                return $candidate;
            })->filter(fn (Incident $candidate): bool => $candidate->distance_km <= 30)->values();

        $incident->loadMissing('category:id,slug,name,icon');
        $isWeather = $incident->category->slug === 'weather_flood';
        $anchor = $isWeather ? $incident : $candidates->first(fn (Incident $candidate): bool => $candidate->category->slug === 'weather_flood');
        $related = $isWeather ? $candidates->reject(fn (Incident $candidate): bool => $candidate->category->slug === 'weather_flood') : $candidates->reject(fn (Incident $candidate): bool => $anchor !== null && $candidate->id === $anchor->id);
        $station = $this->nearestStation($incident->latitude, $incident->longitude, 50);
        if ($anchor === null && $station === null) return null;

        return [
            'weather_event' => $anchor ? [
                'id' => $anchor->public_id, 'title' => $anchor->title, 'occurred_at' => $anchor->published_at?->toIso8601String(),
                'distance_km' => $anchor->id === $incident->id ? 0 : round((float) $anchor->distance_km, 1),
            ] : null,
            'station' => $station,
            'related_groups' => $related->groupBy('category.slug')->map(fn (Collection $items): array => [
                'category' => $items->first()->category->name, 'icon' => $items->first()->category->icon, 'count' => $items->count(),
            ])->values()->all(),
            'window_hours' => 12, 'radius_km' => 30,
            'disclaimer' => 'Coincidencia temporal y territorial. AlertaPY no afirma una relación causal entre estos eventos.',
        ];
    }

    /** @return array<string, mixed>|null */
    public function nearestStation(float $latitude, float $longitude, float $radiusKm = 100): ?array
    {
        $box = $this->distance->boundingBox($latitude, $longitude, $radiusKm);
        $station = MonitoringStation::query()->where('kind', 'weather')->where('is_active', true)->where('is_collecting_data', true)
            ->whereBetween('latitude', [$box['south'], $box['north']])->whereBetween('longitude', [$box['west'], $box['east']])->get()
            ->map(function (MonitoringStation $station) use ($latitude, $longitude): MonitoringStation {
                $station->setAttribute('distance_km', $this->distance->kilometers($latitude, $longitude, $station->latitude, $station->longitude));
                return $station;
            })->filter(fn (MonitoringStation $station): bool => $station->distance_km <= $radiusKm)->sortBy('distance_km')->first();
        if ($station === null) return null;
        $measurements = $station->measurements()->latest('measured_at')->limit(50)->get()->unique('parameter')->mapWithKeys(fn ($item): array => [$item->parameter => ['value' => $item->value, 'unit' => $item->unit]])->all();
        return [
            'id' => $station->id, 'name' => $station->name, 'city' => $station->city, 'department' => $station->department,
            'distance_km' => round($station->distance_km, 1), 'observed_at' => $station->last_observed_at?->toIso8601String(), 'measurements' => $measurements,
            'source' => ['name' => 'DMH/DINAC', 'url' => 'https://www.meteorologia.gov.py/emas/'],
        ];
    }
}
