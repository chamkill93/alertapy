<?php

namespace App\Services;

use App\Enums\LocationMethod;
use App\Models\Incident;
use App\Models\LocationAudit;

class LocationAuditService
{
    private const RANK = ['unknown'=>0,'department'=>1,'district'=>2,'city'=>3,'neighborhood'=>4,'route_locality'=>5,'street_intersection'=>6,'route_intersection'=>7,'route_km_interpolated'=>8,'route_km_official'=>9,'source_coordinates'=>10];

    /** @param array<string, mixed> $metadata */
    public function update(Incident $incident, float $latitude, float $longitude, LocationMethod $method, string $reason, string $source, array $metadata = []): bool
    {
        $oldMethod = $incident->location_method?->value ?? 'unknown';
        if ((self::RANK[$method->value] ?? 0) < (self::RANK[$oldMethod] ?? 0)) return false;
        LocationAudit::query()->create(['incident_id'=>$incident->id,'old_latitude'=>$incident->latitude,'old_longitude'=>$incident->longitude,'new_latitude'=>$latitude,'new_longitude'=>$longitude,'old_method'=>$oldMethod,'new_method'=>$method->value,'reason'=>$reason,'source'=>$source,'metadata_json'=>$metadata,'audited_at'=>now()]);
        $incident->forceFill(['latitude'=>$latitude,'longitude'=>$longitude,'location_method'=>$method])->save();
        return true;
    }
}
