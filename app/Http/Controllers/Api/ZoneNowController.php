<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\WeatherAlert;
use App\Services\ClimateContextService;
use App\Services\GeoDistanceService;
use App\Services\IncidentQueryService;
use App\Services\NearbyInfrastructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZoneNowController extends Controller
{
    public function __invoke(Request $request, IncidentQueryService $query, GeoDistanceService $distance, NearbyInfrastructureService $infrastructure, ClimateContextService $climate): JsonResponse
    {
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-27.7,-19.1'], 'lng' => ['required', 'numeric', 'between:-62.7,-54.2'], 'radius_km' => ['required', 'numeric', 'in:1,3,5,10,25']]);
        $lat = (float) $data['lat']; $lng = (float) $data['lng']; $radius = (float) $data['radius_km'];
        $incidents = $query->apply(Incident::query(), ['period' => 'now', 'near_lat' => $lat, 'near_lng' => $lng, 'radius_km' => $radius])
            ->with('category:id,slug,name,icon,color')->orderByDesc('published_at')->limit(100)->get()
            ->map(function (Incident $incident) use ($distance, $lat, $lng): Incident {
                $incident->setAttribute('distance_km', $distance->kilometers($lat, $lng, $incident->latitude, $incident->longitude)); return $incident;
            });
        $groups = $incidents->groupBy('category.slug')->map(fn ($items): array => [
            'slug' => $items->first()->category->slug, 'name' => $items->first()->category->name, 'icon' => $items->first()->category->icon,
            'count' => $items->count(), 'nearest_distance_km' => round((float) $items->min('distance_km'), 1),
        ])->sortByDesc('count')->values();
        $alerts = WeatherAlert::query()->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->get()
            ->filter(fn (WeatherAlert $alert): bool => $this->contains($alert->geometry_geojson, $lat, $lng))->map(fn (WeatherAlert $alert): array => [
                'event' => $alert->event, 'headline' => $alert->headline, 'severity' => $alert->severity, 'expires_at' => $alert->expires_at?->toIso8601String(),
            ])->values();
        return response()->json(['data' => [
            'radius_km' => $radius, 'incident_count' => $incidents->count(), 'incident_groups' => $groups,
            'message' => $incidents->isEmpty() ? 'Sin incidencias registradas dentro del radio según las fuentes disponibles.' : "{$incidents->count()} incidencias vigentes dentro del radio seleccionado.",
            'weather_station' => $climate->nearestStation($lat, $lng), 'weather_alerts' => $alerts,
            'infrastructure' => $infrastructure->summary($lat, $lng, min($radius, 10)), 'refreshed_at' => now()->toIso8601String(),
        ]]);
    }

    private function contains(?array $geometry, float $lat, float $lng): bool
    {
        $polygon = $geometry['coordinates'][0] ?? null; if (! is_array($polygon)) return false;
        $inside = false; $count = count($polygon);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = $polygon[$i]; [$xj, $yj] = $polygon[$j];
            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) $inside = ! $inside;
        }
        return $inside;
    }
}
