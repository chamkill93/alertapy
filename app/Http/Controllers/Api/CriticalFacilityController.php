<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CriticalFacility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CriticalFacilityController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bbox' => ['required', 'string', 'max:120'],
            'kinds' => ['required', 'string', 'max:100'],
            'zoom' => ['nullable', 'integer', 'between:5,18'],
        ]);
        $parts = array_map('trim', explode(',', $validated['bbox']));
        abort_unless(count($parts) === 4 && count(array_filter($parts, 'is_numeric')) === 4, 422, 'Bounding box inválido.');
        [$south, $west, $north, $east] = array_map('floatval', $parts);
        abort_unless(
            $south >= -90 && $north <= 90 && $west >= -180 && $east <= 180
                && $south < $north && $west < $east,
            422,
            'Bounding box inválido.',
        );

        $kinds = array_values(array_unique(array_filter(array_map('trim', explode(',', $validated['kinds'])))));
        validator(['kinds' => $kinds], ['kinds' => ['required', 'array', 'max:3'], 'kinds.*' => [Rule::in(['health', 'education', 'police'])]])->validate();

        $zoom = (int) ($validated['zoom'] ?? 8);
        if ($north < -27.8 || $south > -19.0 || $east < -63.0 || $west > -54.0) {
            return response()->json([
                'data' => [],
                'meta' => ['total' => 0, 'truncated' => false, 'aggregated' => $zoom < 8],
            ]);
        }

        $south = max($south, -27.8);
        $north = min($north, -19.0);
        $west = max($west, -63.0);
        $east = min($east, -54.0);
        $query = CriticalFacility::query()->whereIn('kind', $kinds)
            ->whereBetween('latitude', [$south, $north])->whereBetween('longitude', [$west, $east]);
        $countsByKind = (clone $query)->selectRaw('kind, COUNT(*) AS aggregate_count')
            ->groupBy('kind')->pluck('aggregate_count', 'kind');
        $total = (int) $countsByKind->sum();

        if ($zoom < 8) {
            $cellSize = $zoom <= 6 ? 0.65 : 0.30;
            $driver = $query->getModel()->getConnection()->getDriverName();
            $gridFunction = $driver === 'sqlite'
                ? 'CAST(((%s - ?) / ?) AS INTEGER)'
                : 'FLOOR((%s - ?) / ?)';
            $latitudeGrid = sprintf($gridFunction, 'latitude');
            $longitudeGrid = sprintf($gridFunction, 'longitude');
            $groups = (clone $query)->toBase()
                ->selectRaw(
                    "kind, COUNT(*) AS facility_count, AVG(latitude) AS center_latitude, AVG(longitude) AS center_longitude, {$latitudeGrid} AS grid_row, {$longitudeGrid} AS grid_column",
                    [$south, $cellSize, $west, $cellSize],
                )
                ->groupBy('kind', 'grid_row', 'grid_column')
                ->orderBy('kind')
                ->get();
            $labels = ['health' => 'puestos de salud', 'education' => 'centros educativos', 'police' => 'dependencias policiales'];
            $facilities = $groups->map(function (object $group) use ($labels): array {
                $key = $group->kind.'|'.$group->grid_row.'|'.$group->grid_column;
                return [
                    'id' => 'group-'.md5($key), 'kind' => $group->kind,
                    'name' => $group->facility_count.' '.$labels[$group->kind], 'category' => 'Agrupación territorial',
                    'department' => null, 'district' => null,
                    'latitude' => round((float) $group->center_latitude, 7), 'longitude' => round((float) $group->center_longitude, 7),
                    'count' => (int) $group->facility_count, 'is_cluster' => true,
                ];
            })->values();

            return response()->json(['data' => $facilities, 'meta' => ['total' => $total, 'truncated' => false, 'aggregated' => true]]);
        }

        // Small layers consume only what they need; share the remaining global
        // capacity across larger layers instead of discarding unused quotas.
        $remainingLimit = 3000;
        $remainingKinds = count($kinds);
        $limits = [];
        foreach (collect($kinds)->sortBy(fn (string $kind): int => (int) ($countsByKind[$kind] ?? 0)) as $kind) {
            $limits[$kind] = min((int) ($countsByKind[$kind] ?? 0), intdiv($remainingLimit, $remainingKinds));
            $remainingLimit -= $limits[$kind];
            $remainingKinds--;
        }

        $facilities = collect($kinds)->flatMap(fn (string $kind) => $limits[$kind] === 0 ? [] : (clone $query)
            ->where('kind', $kind)
            ->orderBy('id')
            ->limit($limits[$kind])
            ->get()
        )->map(fn (CriticalFacility $facility): array => [
            'id' => $facility->id, 'kind' => $facility->kind, 'name' => $facility->name, 'category' => $facility->category,
            'department' => $facility->department, 'district' => $facility->district,
            'latitude' => $facility->latitude, 'longitude' => $facility->longitude, 'count' => 1, 'is_cluster' => false,
        ]);

        return response()->json(['data' => $facilities->values(), 'meta' => ['total' => $total, 'truncated' => $total > $facilities->count(), 'aggregated' => false]]);
    }
}
