<?php

namespace App\Console\Commands;

use App\Enums\IncidentGeometryType;
use App\Enums\IncidentScope;
use App\Enums\SourceRole;
use App\Enums\TemporalAccuracy;
use App\Models\Category;
use App\Models\Incident;
use App\Models\IncidentSource;
use App\Services\IncidentDataQualityService;
use App\Services\IncidentLifecycleService;
use App\Services\IncidentSubcategoryClassifier;
use App\Services\LocationQualityAnalyzer;
use App\Services\PublicationExplanationService;
use Illuminate\Console\Command;

class BackfillReleaseCandidateCommand extends Command
{
    protected $signature = 'alertapy:data:backfill {--temporal} {--subcategory} {--freshness} {--geometry} {--source-role} {--dry-run} {--limit=}';
    protected $description = 'Completa metadatos RC1 de forma idempotente, acotada y auditable.';

    public function handle(IncidentLifecycleService $lifecycle, IncidentSubcategoryClassifier $subcategories, LocationQualityAnalyzer $quality, PublicationExplanationService $explanation, IncidentDataQualityService $dataQuality): int
    {
        $selected = collect(['temporal','subcategory','freshness','geometry','source-role'])->filter(fn (string $name): bool => (bool) $this->option($name));
        $all = $selected->isEmpty(); $dryRun = (bool) $this->option('dry-run'); $limit = max(1, min(100000, (int) ($this->option('limit') ?: 5000)));
        $incidents = Incident::query()->with(['category','sources.source:id,slug','road:id,length_meters'])->orderBy('id')->limit($limit)->get();
        $senepaCategoryId = Category::query()->where('slug', 'health_vector')->value('id');
        $changed = 0;
        foreach ($incidents as $incident) {
            $values = [];
            if ($all || $selected->contains('temporal')) {
                $values += ['detected_at'=>$incident->detected_at ?? $incident->created_at,'first_seen_at'=>$incident->first_seen_at ?? $incident->created_at,'last_seen_at'=>$incident->last_seen_at ?? $incident->updated_at,'lifecycle_status'=>$lifecycle->derive($incident)];
                if ($incident->occurred_at === null && $incident->status->value === 'scheduled' && $incident->starts_at !== null) $values += ['occurred_at'=>$incident->starts_at,'occurred_at_accuracy'=>TemporalAccuracy::Exact,'temporal_evidence_json'=>['method'=>'LEGACY_SCHEDULED_START']];
            }
            if ($all || $selected->contains('subcategory')) {
                $isPrimarySenepa = $incident->sources->contains(
                    fn (IncidentSource $reference): bool => $reference->source?->slug === 'senepa'
                        && in_array($reference->source_role?->value, ['origin', 'primary'], true),
                );
                $categorySlug = $isPrimarySenepa ? 'health_vector' : $incident->category->slug;
                if ($isPrimarySenepa && $senepaCategoryId !== null) {
                    $values['category_id'] = $senepaCategoryId;
                }
                if ($incident->subcategory === null || $isPrimarySenepa) {
                    $values['subcategory'] = $subcategories->classify($categorySlug, $incident->title.' '.$incident->summary);
                }
            }
            if ($all || $selected->contains('geometry')) {
                $analysis = $quality->analyze($incident); $values += ['location_method'=>$analysis['method'],'location_quality'=>$analysis['quality'],'location_quality_flags'=>$analysis['flags']];
                $values['geometry_type'] = $incident->road_id ? IncidentGeometryType::RouteSegment : match ($incident->representation_type) {'line'=>IncidentGeometryType::Line,'polygon'=>IncidentGeometryType::Polygon,'station'=>IncidentGeometryType::Station,default=>($incident->latitude !== null ? IncidentGeometryType::Point : IncidentGeometryType::None)};
                $values['incident_scope'] = $incident->road_id ? IncidentScope::RouteSegment : ($incident->city ? IncidentScope::City : ($incident->district ? IncidentScope::District : ($incident->department ? IncidentScope::Department : IncidentScope::Unknown)));
            }
            // Los indicadores derivados deben observar los valores RC1 que se
            // guardarán en esta misma pasada; así el backfill es idempotente.
            $incident->forceFill($values);
            $values['publication_explanation_json'] = $explanation->forIncident($incident);
            $values['data_quality_json'] = $dataQuality->forIncident($incident);
            $incident->forceFill($values);
            if ($incident->isDirty()) {
                $changed++;
                if (! $dryRun) {
                    $incident->save();
                }
            }
        }
        if ($all || $selected->contains('source-role')) {
            IncidentSource::query()->orderBy('incident_id')->orderBy('detected_at')->get()->groupBy('incident_id')->each(function ($references) use ($dryRun): void {
                foreach ($references->values() as $index => $reference) {
                    $role = $index === 0 ? SourceRole::Primary : SourceRole::Corroborating;
                    $reference->forceFill(['source_role' => $role]);
                    if (! $dryRun && $reference->isDirty()) {
                        $reference->save();
                    }
                }
            });
        }
        $this->info("Procesadas {$incidents->count()} incidencias; {$changed} con metadatos RC1".($dryRun ? ' (DRY RUN).' : '.'));
        return self::SUCCESS;
    }
}
