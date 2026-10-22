<?php

namespace App\Services;

use App\DTOs\RoadLocationDTO;
use App\Models\Road;
use App\Models\RoadAlias;
use App\Models\RoadReferencePoint;
use App\Models\TerritorialGeometry;
use Illuminate\Support\Str;

class RoadKilometerInterpolator
{
    public function __construct(private readonly GeometryService $geometry, private readonly RoadNameNormalizer $normalizer) {}

    public function resolve(RoadLocationDTO $parsed): RoadLocationDTO
    {
        $road = $this->road($parsed->roadCode);
        if ($road === null) return $parsed;
        $base = ['originalText' => $parsed->originalText, 'roadId' => $road->id, 'roadCode' => $road->code, 'kilometer' => $parsed->kilometer, 'relativeOffsetM' => $parsed->relativeOffsetM, 'department' => $parsed->department, 'district' => $parsed->district, 'city' => $parsed->city, 'neighborhood' => $parsed->neighborhood, 'intersectionText' => $parsed->intersectionText, 'directionUnknown' => $parsed->directionUnknown];

        if ($parsed->kilometer !== null) {
            $exact = $road->referencePoints()->whereNotNull('kilometer')->whereBetween('kilometer', [$parsed->kilometer - .001, $parsed->kilometer + .001])->first();
            if ($exact !== null) {
                $official = $exact->reference_type === 'KM_MARKER'; $method = $official ? 'ROUTE_KM_OFFICIAL' : 'ROUTE_KM_OBSERVED';
                return new RoadLocationDTO(...$base, latitude: $exact->latitude, longitude: $exact->longitude, resolutionMethod: $method, accuracy: $official ? 'route_km_official' : 'route_km_observed', estimatedErrorMeters: $this->error($exact, $official ? 50 : 75), evidence: [...$this->evidence($road, $parsed, [$exact->kilometer]), 'method'=>$method, 'reference_type'=>$exact->reference_type, 'reference_source_url'=>$exact->source_url]);
            }

            $before = $road->referencePoints()->whereNotNull('kilometer')->where('kilometer', '<', $parsed->kilometer)->orderByDesc('kilometer')->first();
            $after = $road->referencePoints()->whereNotNull('kilometer')->where('kilometer', '>', $parsed->kilometer)->orderBy('kilometer')->first();
            if ($before !== null && $after !== null && $after->kilometer > $before->kilometer) {
                $beforeProjection = $this->referenceProjection($road, $before); $afterProjection = $this->referenceProjection($road, $after);
                if ($beforeProjection !== null && $afterProjection !== null && abs($afterProjection['offset_meters'] - $beforeProjection['offset_meters']) > 1) {
                    $ratio = ($parsed->kilometer - $before->kilometer) / ($after->kilometer - $before->kilometer);
                    $targetOffset = $beforeProjection['offset_meters'] + ($afterProjection['offset_meters'] - $beforeProjection['offset_meters']) * $ratio; $point = $this->pointAtOffset($road, $targetOffset);
                    if ($point !== null) {
                        $error = (int) ceil(max(100, $beforeProjection['distance_meters'], $afterProjection['distance_meters'], (($after->kilometer - $before->kilometer) * 1000) * .03));
                        return new RoadLocationDTO(...$base, latitude: $point['latitude'], longitude: $point['longitude'], resolutionMethod: 'ROUTE_KM_INTERPOLATED', accuracy: 'route_km_interpolated', estimatedErrorMeters: $error, evidence: [...$this->evidence($road, $parsed, [$before->kilometer, $after->kilometer]), 'reference_types'=>[$before->reference_type,$after->reference_type]]);
                    }
                }
            }
        }

        $locality = $parsed->neighborhood ?: $parsed->city ?: $parsed->district;
        if ($locality !== null) {
            $normalized = Str::lower(Str::ascii($locality));
            $territories = TerritorialGeometry::query()->with('location')->whereHas('location', fn ($query) => $query->where('normalized_name', $normalized))->where('bbox_north', '>=', $road->bbox_south)->where('bbox_south', '<=', $road->bbox_north)->where('bbox_east', '>=', $road->bbox_west)->where('bbox_west', '<=', $road->bbox_east)->get();
            foreach ($territories as $territory) {
                $candidates = $road->segments()->where('bbox_north', '>=', $territory->bbox_south)->where('bbox_south', '<=', $territory->bbox_north)->where('bbox_east', '>=', $territory->bbox_west)->where('bbox_west', '<=', $territory->bbox_east)->toBase()->get(['start_lat', 'start_lng', 'end_lat', 'end_lng', 'segment_length_meters'])->values();
                $segment = $this->representativeSegmentInsideTerritory($candidates, $territory->geometry_json);
                if ($segment === null) continue;
                $territorialBase = [...$base, 'district' => $parsed->district ?: $territory->location?->name, 'city' => $parsed->city];
                return new RoadLocationDTO(...$territorialBase, latitude: ($segment->start_lat + $segment->end_lat) / 2, longitude: ($segment->start_lng + $segment->end_lng) / 2, resolutionMethod: 'ROUTE_LOCALITY', accuracy: 'route_locality', estimatedErrorMeters: (int) max(100, $candidates->sum('segment_length_meters') / 2), evidence: [...$this->evidence($road, $parsed, []), 'method'=>'ROUTE_LOCALITY', 'territorial_geometry_source'=>$territory->source, 'territorial_geometry_date'=>$territory->source_date?->toDateString(), 'candidate_segments'=>$candidates->count(), 'selection'=>'verified_inside_official_geometry']);
            }

            // Older imported networks may carry locality labels directly on a
            // segment. Query only plausible values instead of hydrating every
            // segment of a national route and normalizing it in PHP.
            $variants = array_values(array_unique([$locality, Str::upper($locality), Str::title($locality)]));
            $segments = $road->segments()
                ->where(function ($query) use ($variants): void {
                    $query->whereIn('city', $variants)->orWhereIn('district', $variants);
                })
                ->toBase()
                ->get(['start_lat', 'start_lng', 'end_lat', 'end_lng', 'segment_length_meters']);
            if ($segments->isNotEmpty()) {
                $segment = $segments[(int) floor(($segments->count() - 1) / 2)];
                return new RoadLocationDTO(...$base, latitude: ($segment->start_lat + $segment->end_lat) / 2, longitude: ($segment->start_lng + $segment->end_lng) / 2, resolutionMethod: 'ROUTE_LOCALITY', accuracy: 'route_locality', estimatedErrorMeters: (int) max(100, $segments->sum('segment_length_meters') / 2), evidence: $this->evidence($road, $parsed, []));
            }
        }

        return new RoadLocationDTO(...$base, resolutionMethod: 'ROUTE_ONLY', accuracy: 'route_only', evidence: $this->evidence($road, $parsed, []));
    }

    public function road(?string $code): ?Road
    {
        if ($code === null) return null;
        $normalized = $this->normalizer->normalize($code);
        return Road::query()->active()->where('normalized_code', $normalized)->first()
            ?? RoadAlias::query()->where('normalized_alias', $normalized)->with('road')->get()->pluck('road')->first(fn ($road) => $road?->active);
    }

    private function referenceProjection(Road $road, RoadReferencePoint $reference): ?array
    {
        $bbox = $this->geometry->boundingBoxAround($reference->latitude, $reference->longitude, 2); $best = null;
        foreach ($road->segments()->where('bbox_north','>=',$bbox['south'])->where('bbox_south','<=',$bbox['north'])->where('bbox_east','>=',$bbox['west'])->where('bbox_west','<=',$bbox['east'])->cursor() as $segment) {
            $projection = $this->geometry->closestPointOnSegment($reference->latitude, $reference->longitude, $segment->start_lat, $segment->start_lng, $segment->end_lat, $segment->end_lng);
            if ($best === null || $projection['distance_meters'] < $best['distance_meters']) $best = ['distance_meters'=>$projection['distance_meters'],'offset_meters'=>$segment->cumulative_start_meters+($segment->segment_length_meters*$projection['t'])];
        }
        return $best;
    }

    private function pointAtOffset(Road $road, float $offset): ?array
    {
        $segment = $road->segments()->where('cumulative_start_meters','<=',$offset)->where('cumulative_end_meters','>=',$offset)->first();
        if ($segment === null) return null; $length = max(.001,$segment->segment_length_meters); $ratio = max(0,min(1,($offset-$segment->cumulative_start_meters)/$length));
        return ['latitude'=>$segment->start_lat+(($segment->end_lat-$segment->start_lat)*$ratio),'longitude'=>$segment->start_lng+(($segment->end_lng-$segment->start_lng)*$ratio)];
    }

    private function error(RoadReferencePoint $point, int $fallback): int
    {
        return is_numeric($point->accuracy) ? max(1, (int) $point->accuracy) : $fallback;
    }

    private function evidence(Road $road, RoadLocationDTO $parsed, array $references): array
    {
        return ['original_text' => $parsed->originalText, 'road' => $road->code, 'kilometer' => $parsed->kilometer, 'method' => $references === [] ? 'ROUTE_ONLY' : (count($references) === 1 ? 'ROUTE_KM_OFFICIAL' : 'ROUTE_KM_INTERPOLATED'), 'reference_points' => $references, 'dataset' => $road->dataset?->name, 'dataset_id' => $road->source_dataset_id, 'relative_offset_m' => $parsed->relativeOffsetM, 'direction_unknown' => $parsed->directionUnknown];
    }

    private function pointInTerritory(float $lat, float $lng, array $geometry): bool
    {
        $polygons = ($geometry['type'] ?? null) === 'MultiPolygon' ? ($geometry['coordinates'] ?? []) : [($geometry['coordinates'] ?? [])];
        foreach ($polygons as $polygon) if (isset($polygon[0]) && is_array($polygon[0]) && $this->geometry->pointInPolygon($lat, $lng, $polygon[0])) return true;
        return false;
    }

    private function representativeSegmentInsideTerritory($segments, array $geometry): ?object
    {
        $count = $segments->count();
        if ($count === 0) return null;

        $center = (int) floor(($count - 1) / 2);
        $visited = [];

        for ($offset = 0; $offset < $count; $offset++) {
            foreach ([$center + $offset, $center - $offset] as $index) {
                if ($index < 0 || $index >= $count || isset($visited[$index])) continue;
                $visited[$index] = true;
                $segment = $segments[$index];
                $midLat = ($segment->start_lat + $segment->end_lat) / 2;
                $midLng = ($segment->start_lng + $segment->end_lng) / 2;

                if ($this->pointInTerritory($midLat, $midLng, $geometry)
                    || $this->pointInTerritory($segment->start_lat, $segment->start_lng, $geometry)
                    || $this->pointInTerritory($segment->end_lat, $segment->end_lng, $geometry)) {
                    return $segment;
                }
            }
        }

        return null;
    }
}
