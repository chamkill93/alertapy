<?php

namespace App\Console\Commands;

use App\Enums\LocationAccuracy;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Services\LocationResolutionPipeline;
use Illuminate\Console\Command;

class ReprocessIncidentLocationsCommand extends Command
{
    protected $signature = 'alertapy:locations:reprocess {--dry-run} {--only-low-accuracy} {--limit=500}';
    protected $description = 'Mejora ubicaciones históricas con evidencia vial, sin degradar precisión existente.';
    public function handle(LocationResolutionPipeline $pipeline): int
    {
        $query = Incident::query()->orderBy('id')->limit(max(1, min(5000, (int) $this->option('limit'))));
        if ($this->option('only-low-accuracy')) $query->whereIn('location_accuracy', [LocationAccuracy::City->value, LocationAccuracy::District->value, LocationAccuracy::Department->value, LocationAccuracy::Approximate->value]);
        $upgraded = 0;
        foreach ($query->get() as $incident) {
            $resolved = $pipeline->resolve($incident->title.' '.$incident->summary, city: $incident->city, district: $incident->district, department: $incident->department);
            if ($resolved === null || ! $resolved->hasCoordinates() || ! in_array($resolved->accuracy, [LocationAccuracy::RouteKmOfficial->value, LocationAccuracy::RouteKmObserved->value, LocationAccuracy::RouteKmInterpolated->value, LocationAccuracy::RouteIntersection->value, LocationAccuracy::RouteLocality->value], true)) continue;
            $upgraded++; if ($this->option('dry-run')) continue;
            $before = ['latitude' => $incident->latitude, 'longitude' => $incident->longitude, 'accuracy' => $incident->location_accuracy->value];
            $incident->update(['road_id' => $resolved->roadId, 'road_code' => $resolved->roadCode, 'road_kilometer' => $resolved->kilometer, 'road_location_method' => $resolved->resolutionMethod, 'latitude' => $resolved->latitude, 'longitude' => $resolved->longitude, 'location_accuracy' => $resolved->accuracy, 'location_error_meters' => $resolved->estimatedErrorMeters, 'location_evidence_json' => [...$resolved->evidence, 'before' => $before, 'upgraded_at' => now()->toIso8601String()]]);
            IncidentUpdate::query()->create(['incident_id' => $incident->id, 'title' => 'location_resolution_upgrade', 'summary' => "Ubicación mejorada de {$before['accuracy']} a {$resolved->accuracy} mediante {$resolved->resolutionMethod}.", 'status' => $incident->status, 'occurred_at' => now()]);
        }
        $this->info(($this->option('dry-run') ? 'Mejoras posibles' : 'Ubicaciones mejoradas').": {$upgraded}"); return self::SUCCESS;
    }
}
