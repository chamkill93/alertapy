<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\MonitoringStation;
use App\Models\RoadSegment;

class IncidentImpactService
{
    public function __construct(private readonly TerritorialImpactService $territorial, private readonly NearbyInfrastructureService $infrastructure, private readonly GeometryService $geometry) {}

    public function forIncident(Incident $incident): ?array
    {
        if ($incident->usesReferenceLocation()) {
            return null;
        }

        $context = $this->territorial->forIncident($incident);
        if ($incident->latitude === null || $incident->longitude === null) return $context;
        $radius = 2.0; $box = $this->geometry->boundingBoxAround($incident->latitude, $incident->longitude, $radius);
        $roads = RoadSegment::query()->where('bbox_north', '>=', $box['south'])->where('bbox_south', '<=', $box['north'])->where('bbox_east', '>=', $box['west'])->where('bbox_west', '<=', $box['east'])->with('road:id,code')->limit(250)->get()->filter(fn ($segment) => $this->geometry->closestPointOnSegment($incident->latitude, $incident->longitude, $segment->start_lat, $segment->start_lng, $segment->end_lat, $segment->end_lng)['distance_meters'] <= $radius * 1000)->pluck('road.code')->filter()->unique()->values();
        $stations = MonitoringStation::query()->where('is_active', true)->whereBetween('latitude', [$box['south'], $box['north']])->whereBetween('longitude', [$box['west'], $box['east']])->get()->filter(fn ($station) => $this->geometry->haversine($incident->latitude, $incident->longitude, $station->latitude, $station->longitude) <= $radius * 1000)->count();
        $infrastructure = $this->infrastructure->summary($incident->latitude, $incident->longitude, $radius);
        if ($context === null && $roads->isEmpty() && $stations === 0 && $infrastructure['total'] === 0) return null;
        $method = $incident->impact_method ?: 'UNKNOWN';
        if ($method === 'UNKNOWN' && $context !== null) $method = 'TERRITORIAL_CONTEXT';
        return [...($context ?? ['territory' => ['department' => $incident->department, 'district' => $incident->district ?: $incident->city]]), 'impact_method' => $method, 'population_potential' => in_array($method, ['EXACT_SOURCE', 'OFFICIAL_AREA', 'ESTIMATED_AREA'], true) ? data_get($incident->impact_evidence_json, 'population_potential') : null, 'nearby' => ['infrastructure' => $infrastructure, 'roads' => $roads, 'dmh_stations' => $stations], 'methodology_notice' => $method === 'TERRITORIAL_CONTEXT' ? 'Alcance territorial informado. No existe geometría suficiente para estimar población afectada.' : 'La estimación utiliza únicamente geometría y evidencia territorial almacenadas.'];
    }
}
