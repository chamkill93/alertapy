<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonitoringStation;
use Illuminate\Http\JsonResponse;

class MonitoringStationController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $stations = MonitoringStation::query()
            ->with('source:id,name,slug,type')
            ->where('is_active', true)
            ->get()
            ->map(fn (MonitoringStation $station): array => [
                'id' => $station->id,
                'name' => $station->name,
                'kind' => $station->kind,
                'city' => $station->city,
                'department' => $station->department,
                'latitude' => $station->latitude,
                'longitude' => $station->longitude,
                'is_collecting_data' => $station->is_collecting_data,
                'is_under_maintenance' => $station->is_under_maintenance,
                'last_observed_at' => $station->last_observed_at?->toIso8601String(),
                'source' => $station->source?->only(['name', 'slug', 'type']),
                'measurements' => $station->measurements()->latest('measured_at')->limit(50)->get()->unique('parameter')->values(),
            ]);

        return response()->json(['data' => $stations]);
    }

    public function show(MonitoringStation $station): JsonResponse
    {
        abort_unless($station->is_active, 404);
        $station->load('source:id,name,slug,type');

        return response()->json(['data' => $this->stationData($station)]);
    }

    public function measurements(MonitoringStation $station): JsonResponse
    {
        abort_unless($station->is_active, 404);
        $measurements = $station->measurements()
            ->latest('measured_at')
            ->limit(500)
            ->get();

        return response()->json(['data' => $measurements]);
    }

    /** @return array<string, mixed> */
    private function stationData(MonitoringStation $station): array
    {
        return [
            'id' => $station->id,
            'name' => $station->name,
            'kind' => $station->kind,
            'city' => $station->city,
            'department' => $station->department,
            'latitude' => $station->latitude,
            'longitude' => $station->longitude,
            'is_collecting_data' => $station->is_collecting_data,
            'is_under_maintenance' => $station->is_under_maintenance,
            'last_observed_at' => $station->last_observed_at?->toIso8601String(),
            'source' => $station->source?->only(['name', 'slug', 'type']),
        ];
    }
}
