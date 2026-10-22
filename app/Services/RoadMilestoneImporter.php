<?php

namespace App\Services;

use App\Models\RoadReferencePoint;
use App\Models\RoadSegment;
use RuntimeException;

class RoadMilestoneImporter
{
    public function __construct(private readonly GeometryService $geometry) {}

    /** @return array<string, int> */
    public function import(string $path, bool $dryRun = false, float $maxDistanceMeters = 60): array
    {
        $real = realpath($path); if ($real === false || ! is_readable($real)) throw new RuntimeException('El archivo de hitos no existe o no puede leerse.');
        $data = json_decode((string) file_get_contents($real), true, 512, JSON_THROW_ON_ERROR); $elements = (array) ($data['elements'] ?? []);
        $stats = ['detected'=>count($elements),'valid'=>0,'imported'=>0,'duplicates'=>0,'unmatched'=>0,'ambiguous'=>0,'implausible'=>0];
        foreach ($elements as $element) {
            $tags = (array) ($element['tags'] ?? []); $latitude = $element['lat'] ?? null; $longitude = $element['lon'] ?? null; $kilometer = $this->kilometer($tags);
            if (($element['type'] ?? null) !== 'node' || ! is_numeric($latitude) || ! is_numeric($longitude) || $kilometer === null) { $stats['unmatched']++; continue; }
            $latitude = (float) $latitude; $longitude = (float) $longitude; $bbox = $this->geometry->boundingBoxAround($latitude, $longitude, $maxDistanceMeters / 1000);
            $byRoad = [];
            foreach (RoadSegment::query()->whereHas('road', fn ($query) => $query->active()->where('normalized_code', 'like', 'PY%'))->where('bbox_north','>=',$bbox['south'])->where('bbox_south','<=',$bbox['north'])->where('bbox_east','>=',$bbox['west'])->where('bbox_west','<=',$bbox['east'])->with('road')->get() as $segment) {
                $point = $this->geometry->closestPointOnSegment($latitude, $longitude, $segment->start_lat, $segment->start_lng, $segment->end_lat, $segment->end_lng);
                if ($point['distance_meters'] <= $maxDistanceMeters && (! isset($byRoad[$segment->road_id]) || $point['distance_meters'] < $byRoad[$segment->road_id]['distance'])) $byRoad[$segment->road_id] = ['road'=>$segment->road,'distance'=>$point['distance_meters']];
            }
            $candidates = array_values($byRoad); usort($candidates, fn ($a,$b) => $a['distance'] <=> $b['distance']);
            if ($candidates === []) { $stats['unmatched']++; continue; }
            if (isset($candidates[1]) && abs($candidates[1]['distance'] - $candidates[0]['distance']) < 12) { $stats['ambiguous']++; continue; }
            $road = $candidates[0]['road']; $geometricLengthKm = max(1, $road->length_meters / 1000);
            if ($kilometer < 0 || $kilometer > max(50, $geometricLengthKm * 1.35)) { $stats['implausible']++; continue; }
            $stats['valid']++;
            $existing = RoadReferencePoint::query()->where('road_id',$road->id)->whereBetween('kilometer',[$kilometer-.001,$kilometer+.001])->first();
            if ($existing !== null) { $stats['duplicates']++; continue; }
            if (! $dryRun) RoadReferencePoint::query()->create(['road_id'=>$road->id,'source_dataset_id'=>$road->source_dataset_id,'reference_type'=>'KM_MARKER_OBSERVED','kilometer'=>$kilometer,'latitude'=>$latitude,'longitude'=>$longitude,'name'=>'Hito km '.$kilometer,'source_url'=>'https://www.openstreetmap.org/node/'.($element['id'] ?? ''),'accuracy'=>(string) max(10,(int) ceil($candidates[0]['distance']+15))]);
            $stats['imported']++;
        }
        return $stats;
    }

    private function kilometer(array $tags): ?float
    {
        foreach ([$tags['distance'] ?? null, $tags['ref'] ?? null, $tags['name'] ?? null] as $value) {
            if (! is_scalar($value)) continue; $text = str_replace(',', '.', (string) $value);
            if (preg_match('/(?:^|\bkm\.?\s*)(\d{1,4}(?:\.\d{1,3})?)(?:\b|$)/i', $text, $match) === 1) return (float) $match[1];
        }
        return null;
    }
}
