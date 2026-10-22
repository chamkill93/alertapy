<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Road;
use App\Services\LocationResolutionPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RoadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bbox' => ['sometimes', 'string', 'max:100'],
            'code' => ['sometimes', 'string', 'max:32'],
            'zoom' => ['sometimes', 'integer', 'between:5,18'],
        ]);
        $zoom = (int) ($data['zoom'] ?? 8);
        $query = Road::query()
            ->active()
            ->with('dataset:id,name,source_name,source_url,source_date');
        $bbox = null;

        if (! empty($data['bbox'])) {
            $parts = array_map('floatval', explode(',', $data['bbox']));
            abort_unless(count($parts) === 4, 422);
            [$south, $west, $north, $east] = $parts;
            abort_unless($south < $north && $west < $east, 422);
            $bbox = compact('south', 'west', 'north', 'east');
            $query
                ->where('bbox_north', '>=', $south)
                ->where('bbox_south', '<=', $north)
                ->where('bbox_east', '>=', $west)
                ->where('bbox_west', '<=', $east);
        }

        if (! empty($data['code'])) {
            $query->where('normalized_code', preg_replace('/[^A-Z0-9]/', '', strtoupper($data['code'])));
        }

        $roads = $query
            ->limit(150)
            ->get()
            ->map(fn (Road $road): array => $this->road($road, true, $bbox, $zoom))
            ->filter(fn (array $road): bool => $road['geometry'] !== null)
            ->values();

        return response()
            ->json(['data' => $roads])
            ->header('Cache-Control', 'public, max-age=60');
    }

    public function show(Road $road): JsonResponse
    {
        abort_unless($road->active, 404);

        return response()->json([
            'data' => $this->road(
                $road->load('dataset:id,name,source_name,source_url,source_date'),
                true,
            ),
        ]);
    }

    public function segments(Road $road): JsonResponse
    {
        abort_unless($road->active, 404);

        return response()->json(['data' => $road->segments()->get([
            'sequence', 'start_lat', 'start_lng', 'end_lat', 'end_lng',
            'segment_length_meters', 'department', 'district', 'city',
        ])]);
    }

    public function referencePoints(Road $road): JsonResponse
    {
        abort_unless($road->active, 404);

        return response()->json(['data' => $road->referencePoints()->get([
            'reference_type', 'kilometer', 'latitude', 'longitude', 'name', 'accuracy',
        ])]);
    }

    public function resolve(Request $request, LocationResolutionPipeline $pipeline): JsonResponse
    {
        $data = $request->validate(['query' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $pipeline->resolve($data['query'])]);
    }

    private function road(Road $road, bool $geometry, ?array $bbox = null, int $zoom = 12): array
    {
        return [
            'id' => $road->id,
            'code' => $road->code,
            'name' => $road->official_name ?: $road->name,
            'classification' => $road->classification,
            'department' => $road->department,
            'district' => $road->district,
            'geometry' => $geometry ? $this->mapGeometry($road, $bbox, $zoom) : null,
            'dataset' => $road->dataset?->only(['name', 'source_name', 'source_url', 'source_date']),
        ];
    }

    /**
     * The map may use a visually simplified copy, while all location and
     * kilometre calculations continue using the complete road_segments table.
     */
    private function mapGeometry(Road $road, ?array $bbox, int $zoom): ?array
    {
        $broadView = $bbox === null
            || max($bbox['north'] - $bbox['south'], $bbox['east'] - $bbox['west']) >= 2;

        if ($zoom <= 8 || $broadView) {
            $updated = $road->updated_at?->getTimestamp() ?? 0;
            $cacheKey = "alertapy:road-map-geometry:{$road->id}:{$updated}:z{$zoom}";

            return Cache::remember(
                $cacheKey,
                now()->addDay(),
                fn (): ?array => $this->simplifyGeometry($road->geometry_json, $this->toleranceForZoom($zoom)),
            );
        }

        $visible = $bbox ? $this->visibleGeometry($road, $bbox) : $road->geometry_json;

        return $this->simplifyGeometry($visible, $this->toleranceForZoom($zoom));
    }

    private function toleranceForZoom(int $zoom): float
    {
        return match (true) {
            $zoom <= 6 => 0.008,
            $zoom === 7 => 0.004,
            $zoom === 8 => 0.002,
            $zoom === 9 => 0.0008,
            $zoom === 10 => 0.0003,
            default => 0.0,
        };
    }

    private function simplifyGeometry(?array $geometry, float $tolerance): ?array
    {
        if ($geometry === null || $tolerance <= 0) {
            return $geometry;
        }

        if (($geometry['type'] ?? null) === 'LineString') {
            $coordinates = $this->simplifyLine($geometry['coordinates'] ?? [], $tolerance);

            return count($coordinates) >= 2
                ? ['type' => 'LineString', 'coordinates' => $coordinates]
                : null;
        }

        if (($geometry['type'] ?? null) === 'MultiLineString') {
            $lines = array_values(array_filter(array_map(
                fn (array $line): array => $this->simplifyLine($line, $tolerance),
                $geometry['coordinates'] ?? [],
            ), fn (array $line): bool => count($line) >= 2));

            if ($lines === []) {
                return null;
            }

            return count($lines) === 1
                ? ['type' => 'LineString', 'coordinates' => $lines[0]]
                : ['type' => 'MultiLineString', 'coordinates' => $lines];
        }

        return null;
    }

    /** @param array<int, array{0: float|int, 1: float|int}> $points */
    private function simplifyLine(array $points, float $tolerance): array
    {
        $count = count($points);

        if ($count <= 2) {
            return $points;
        }

        $keep = [0 => true, $count - 1 => true];
        $stack = [[0, $count - 1]];
        $threshold = $tolerance * $tolerance;

        while ($stack !== []) {
            [$start, $end] = array_pop($stack);
            $maximum = 0.0;
            $furthest = null;

            for ($index = $start + 1; $index < $end; $index++) {
                $distance = $this->pointSegmentDistanceSquared($points[$index], $points[$start], $points[$end]);

                if ($distance > $maximum) {
                    $maximum = $distance;
                    $furthest = $index;
                }
            }

            if ($furthest !== null && $maximum > $threshold) {
                $keep[$furthest] = true;
                $stack[] = [$start, $furthest];
                $stack[] = [$furthest, $end];
            }
        }

        $indexes = array_keys($keep);
        sort($indexes, SORT_NUMERIC);

        return array_map(fn (int $index): array => $points[$index], $indexes);
    }

    private function pointSegmentDistanceSquared(array $point, array $start, array $end): float
    {
        $dx = (float) $end[0] - (float) $start[0];
        $dy = (float) $end[1] - (float) $start[1];

        if ($dx === 0.0 && $dy === 0.0) {
            return ((float) $point[0] - (float) $start[0]) ** 2
                + ((float) $point[1] - (float) $start[1]) ** 2;
        }

        $ratio = (((float) $point[0] - (float) $start[0]) * $dx
            + ((float) $point[1] - (float) $start[1]) * $dy) / ($dx * $dx + $dy * $dy);
        $ratio = max(0.0, min(1.0, $ratio));
        $projectedX = (float) $start[0] + $ratio * $dx;
        $projectedY = (float) $start[1] + $ratio * $dy;

        return ((float) $point[0] - $projectedX) ** 2
            + ((float) $point[1] - $projectedY) ** 2;
    }

    private function visibleGeometry(Road $road, array $bbox): ?array
    {
        $segments = $road->segments()
            ->where('bbox_north', '>=', $bbox['south'])
            ->where('bbox_south', '<=', $bbox['north'])
            ->where('bbox_east', '>=', $bbox['west'])
            ->where('bbox_west', '<=', $bbox['east'])
            ->toBase()
            ->get(['start_lat', 'start_lng', 'end_lat', 'end_lng']);

        if ($segments->isEmpty()) {
            return null;
        }

        $lines = [];
        $line = [];

        foreach ($segments as $segment) {
            $start = [(float) $segment->start_lng, (float) $segment->start_lat];
            $end = [(float) $segment->end_lng, (float) $segment->end_lat];
            $last = $line === [] ? null : $line[array_key_last($line)];

            if ($last === null || (abs($last[0] - $start[0]) < 1e-7 && abs($last[1] - $start[1]) < 1e-7)) {
                if ($last === null) {
                    $line[] = $start;
                }
                $line[] = $end;

                continue;
            }

            if (count($line) >= 2) {
                $lines[] = $line;
            }
            $line = [$start, $end];
        }

        if (count($line) >= 2) {
            $lines[] = $line;
        }

        if ($lines === []) {
            return null;
        }

        return count($lines) === 1
            ? ['type' => 'LineString', 'coordinates' => $lines[0]]
            : ['type' => 'MultiLineString', 'coordinates' => $lines];
    }
}
