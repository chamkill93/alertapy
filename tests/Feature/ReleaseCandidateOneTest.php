<?php

namespace Tests\Feature;

use App\Enums\FreshnessStatus;
use App\Enums\IncidentStatus;
use App\Enums\LifecycleStatus;
use App\Enums\LocationAccuracy;
use App\Enums\LocationMethod;
use App\Enums\Severity;
use App\Enums\SourceRole;
use App\Enums\SourceType;
use App\Enums\TemporalAccuracy;
use App\Models\Category;
use App\Models\Incident;
use App\Models\IncidentSource;
use App\Models\IncidentUpdate;
use App\Models\SchedulerCycle;
use App\Models\Source;
use App\Services\IncidentFreshnessService;
use App\Services\IncidentLifecycleService;
use App\Services\IncidentSubcategoryClassifier;
use App\Services\IncidentTemporalService;
use App\Services\IncidentVerificationService;
use App\Services\LocationQualityAnalyzer;
use App\Services\SchedulerHealthService;
use App\Services\TerritorialRelevanceClassifier;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseCandidateOneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_temporal_model_separates_occurrence_from_publication_and_preserves_unknown(): void
    {
        $service = app(IncidentTemporalService::class);
        $published = CarbonImmutable::parse('2026-08-20 14:30:00 America/Asuncion');
        $relative = $service->resolve('El accidente ocurrió anoche cerca de las 22:00.', $published);
        $this->assertSame('2026-08-19 22:00', $relative['occurred_at']?->format('Y-m-d H:i'));
        $this->assertSame(TemporalAccuracy::Approximate, $relative['accuracy']);
        $this->assertNotSame($published->toIso8601String(), $relative['evidence']['parsed_at']);

        $hours = $service->resolve('El hecho ocurrió hace 3 horas.', $published);
        $this->assertSame('2026-08-20 11:30', $hours['occurred_at']?->format('Y-m-d H:i'));
        $this->assertSame(TemporalAccuracy::RelativeParsed, $hours['accuracy']);

        $unknown = $service->resolve('Comunicado sin hora del acontecimiento.', $published);
        $this->assertNull($unknown['occurred_at']);
        $this->assertSame(TemporalAccuracy::Unknown, $unknown['accuracy']);
    }

    public function test_lifecycle_has_six_canonical_states_and_maps_legacy_statuses(): void
    {
        $this->seed(CategorySeeder::class);
        $this->assertSame(['detected','active','monitoring','resolved','expired','discarded'], array_column(LifecycleStatus::cases(), 'value'));
        $service = app(IncidentLifecycleService::class);
        $this->assertSame(LifecycleStatus::Detected, $service->derive($this->incident(IncidentStatus::Reported)));
        $this->assertSame(LifecycleStatus::Active, $service->derive($this->incident(IncidentStatus::Active)));
        $this->assertSame(LifecycleStatus::Monitoring, $service->derive($this->incident(IncidentStatus::Confirmed)));
        $this->assertSame(LifecycleStatus::Resolved, $service->derive($this->incident(IncidentStatus::Finalized)));
        $this->assertSame(LifecycleStatus::Expired, $service->derive($this->incident(IncidentStatus::Active, ['expires_at'=>now()->subMinute()])));
        $this->assertSame(LifecycleStatus::Discarded, $service->derive($this->incident(IncidentStatus::Cancelled)));
    }

    public function test_freshness_uses_category_specific_windows(): void
    {
        CarbonImmutable::setTestNow('2026-08-20 15:00:00 America/Asuncion');
        $this->seed(CategorySeeder::class);
        $service = app(IncidentFreshnessService::class);
        $incident = $this->incident(IncidentStatus::Active, ['last_seen_at'=>now()->subMinutes(30),'expires_at'=>now()->addDay()]);
        $this->assertSame(FreshnessStatus::Live, $service->forIncident($incident));
        $incident->last_seen_at = now()->subHours(3); $this->assertSame(FreshnessStatus::Recent, $service->forIncident($incident));
        $incident->last_seen_at = now()->subHours(10); $this->assertSame(FreshnessStatus::Aging, $service->forIncident($incident));
        $incident->last_seen_at = now()->subHours(30); $this->assertSame(FreshnessStatus::Stale, $service->forIncident($incident));
        $incident->last_seen_at = now()->subDays(3); $this->assertSame(FreshnessStatus::Archived, $service->forIncident($incident));
    }

    public function test_severity_and_verification_are_independent_dimensions(): void
    {
        $this->seed(CategorySeeder::class);
        $official = $this->incident(IncidentStatus::Active, ['source_type'=>SourceType::Official,'severity'=>Severity::Informational,'verification_level'=>'official']);
        $community = $this->incident(IncidentStatus::Reported, ['source_type'=>SourceType::Community,'severity'=>Severity::High,'verification_level'=>'unconfirmed']);
        $verification = app(IncidentVerificationService::class);
        $this->assertSame('official', $verification->forIncident($official)->value);
        $this->assertSame(Severity::Informational, $official->severity);
        $this->assertSame('community', $verification->forIncident($community)->value);
        $this->assertSame(Severity::High, $community->severity);
    }

    public function test_location_quality_detects_methods_false_precision_and_outside_points(): void
    {
        $this->seed(CategorySeeder::class);
        $analyzer = app(LocationQualityAnalyzer::class);
        $native = $analyzer->analyze($this->incident(IncidentStatus::Active, ['location_accuracy'=>LocationAccuracy::Exact,'road_location_method'=>'NATIVE_COORDINATES']));
        $this->assertSame(LocationMethod::SourceCoordinates, $native['method']);
        $this->assertSame('very_high', $native['quality']->value);
        $route = $analyzer->analyze($this->incident(IncidentStatus::Active, ['location_accuracy'=>LocationAccuracy::RouteKmInterpolated,'road_location_method'=>'ROUTE_KM_INTERPOLATED']));
        $this->assertSame(LocationMethod::RouteKmInterpolated, $route['method']);
        $outside = $analyzer->analyze($this->incident(IncidentStatus::Active, ['latitude'=>0,'longitude'=>0,'location_accuracy'=>LocationAccuracy::Department]));
        $this->assertContains('ZERO_COORDINATES', $outside['flags']);
        $this->assertContains('LOW_ACCURACY', $outside['flags']);
        $this->assertContains('CENTROID_ONLY', $outside['flags']);
    }

    public function test_subcategories_and_territorial_relevance_are_deterministic_and_explainable(): void
    {
        $subcategories = app(IncidentSubcategoryClassifier::class);
        $this->assertSame('trabajo_programado', $subcategories->classify('electricity', 'ANDE anuncia trabajo programado en Capiatá'));
        $this->assertSame('cierre', $subcategories->classify('roads', 'Cierre de la ruta PY02 por obras'));
        $this->assertNull($subcategories->classify('water', 'La institución celebró su aniversario'));
        $relevance = app(TerritorialRelevanceClassifier::class);
        $this->assertSame('REJECT', $relevance->classify('Reunión protocolar y saludo institucional')['decision']);
        $publish = $relevance->classify('MOPC anuncia cierre de la PY02 por obras');
        $this->assertSame('PUBLISH', $publish['decision']);
        $this->assertSame('TERRITORIAL_OPERATIONAL_SIGNAL', $publish['rule_id']);
        $this->assertContains('cierre', $publish['matched_tokens']);
    }

    public function test_source_roles_and_structured_timeline_are_exposed_without_breaking_api(): void
    {
        $this->seed(CategorySeeder::class);
        $source = Source::query()->create(['name'=>'Fuente RC1','slug'=>'fuente-rc1','type'=>SourceType::Official,'ingestion_type'=>'html','georef_mode'=>'extract_and_geocode','base_url'=>'https://example.test','domain'=>'example.test','enabled'=>true,'polling_minutes'=>10,'trust_level'=>'high']);
        $incident = $this->incident(IncidentStatus::Active, ['occurred_at'=>now()->subHour(),'occurred_at_accuracy'=>TemporalAccuracy::Exact,'subcategory'=>'cierre']);
        IncidentSource::query()->create(['incident_id'=>$incident->id,'source_id'=>$source->id,'original_title'=>'Cierre vial','original_url'=>'https://example.test/cierre','published_at'=>now(),'detected_at'=>now(),'source_role'=>SourceRole::Origin]);
        IncidentUpdate::query()->create(['incident_id'=>$incident->id,'source_id'=>$source->id,'title'=>'Ruta cerrada','summary'=>'Cierre confirmado','status'=>IncidentStatus::Active,'event_type'=>'road_closed','occurred_at'=>now()]);
        $this->getJson('/api/incidents/'.$incident->public_id)->assertOk()
            ->assertJsonPath('data.subcategory','cierre')
            ->assertJsonPath('data.sources.0.role','origin')
            ->assertJsonPath('data.updates.0.event_type','road_closed')
            ->assertJsonPath('data.occurred_at_accuracy','exact')
            ->assertJsonStructure(['data'=>['freshness','verification_level','scope','publication_explanation','data_quality']]);
    }

    public function test_scheduler_health_covers_missing_recent_stale_and_failed_cycles(): void
    {
        CarbonImmutable::setTestNow('2026-08-20 15:00:00 America/Asuncion');
        config()->set('alertapy.scheduler_stale_minutes', 15);
        $service = app(SchedulerHealthService::class);
        $this->assertSame('MISSING', $service->status()['status']);
        SchedulerCycle::query()->create(['started_at'=>now()->subMinutes(5),'finished_at'=>now()->subMinutes(4),'status'=>'success']);
        $this->assertSame('HEALTHY', $service->status()['status']);
        SchedulerCycle::query()->create(['started_at'=>now()->subMinutes(3),'finished_at'=>now()->subMinutes(2),'status'=>'failed']);
        $this->assertSame('FAILED', $service->status()['status']);
        SchedulerCycle::query()->delete();
        SchedulerCycle::query()->create(['started_at'=>now()->subHour(),'finished_at'=>now()->subMinutes(59),'status'=>'success']);
        $this->assertSame('STALE', $service->status()['status']);
    }

    public function test_backfill_corrects_primary_senepa_actions_to_health_vector(): void
    {
        $this->seed(CategorySeeder::class);
        $source = Source::query()->create(['name'=>'SENEPA','slug'=>'senepa','type'=>SourceType::Official,'ingestion_type'=>'html','georef_mode'=>'extract_and_geocode','base_url'=>'https://senepa.gov.py/','domain'=>'senepa.gov.py','enabled'=>true,'polling_minutes'=>30,'trust_level'=>'high']);
        $incident = $this->incident(IncidentStatus::Active, [
            'category_id' => Category::query()->where('slug', 'security')->value('id'),
            'title' => 'Operativo de prevención de arbovirosis en Encarnación',
            'summary' => 'SENEPA realizó una minga ambiental y rastrillaje en distintos barrios.',
            'subcategory' => 'operativo',
            'source_type' => SourceType::Official,
        ]);
        IncidentSource::query()->create(['incident_id'=>$incident->id,'source_id'=>$source->id,'original_title'=>$incident->title,'original_url'=>'https://senepa.gov.py/2026/08/19/operativo/','published_at'=>now(),'detected_at'=>now(),'source_role'=>SourceRole::Primary]);

        $this->artisan('alertapy:data:backfill --subcategory --limit=10')->assertSuccessful();

        $incident = $incident->fresh();
        $this->assertSame('health_vector', $incident->category->slug);
        $this->assertSame('minga_ambiental', $incident->subcategory);
    }

    public function test_diagnostic_and_backfill_commands_support_safe_operation(): void
    {
        CarbonImmutable::setTestNow('2026-08-20 15:00:00 America/Asuncion');
        $this->seed(CategorySeeder::class);
        $incident = $this->incident(IncidentStatus::Active);
        $this->artisan('alertapy:data:backfill --dry-run --limit=10')->assertSuccessful();
        $this->assertSame('unknown', $incident->fresh()->location_method->value);
        $this->artisan('alertapy:data:backfill --limit=10')->assertSuccessful();
        $this->assertNotSame('unknown', $incident->fresh()->location_method->value);
        $firstBackfillUpdate = $incident->fresh()->updated_at;
        $this->artisan('alertapy:data:backfill --limit=10')
            ->assertSuccessful()
            ->expectsOutputToContain('0 con metadatos RC1');
        $this->assertTrue($firstBackfillUpdate->equalTo($incident->fresh()->updated_at));
        $this->artisan('alertapy:geo:quality --active --dry-run')->assertSuccessful();
        $this->artisan('alertapy:status')->assertSuccessful()->expectsOutputToContain('AlertaPY Status');
    }

    /** @param array<string, mixed> $overrides */
    private function incident(IncidentStatus $status, array $overrides = []): Incident
    {
        $category = Category::query()->where('slug', 'traffic_accident')->firstOrFail();
        return Incident::query()->create([...[
            'category_id'=>$category->id,'title'=>'Incidencia RC1','summary'=>'Evidencia territorial verificable',
            'source_type'=>SourceType::Press,'verification_level'=>'press','status'=>$status,'severity'=>Severity::Medium,
            'latitude'=>-25.3,'longitude'=>-57.6,'location_accuracy'=>LocationAccuracy::City,
            'department'=>'Central','city'=>'San Lorenzo','location_text'=>'San Lorenzo',
            'published_at'=>now()->subMinutes(10),'starts_at'=>now()->subMinutes(10),'expires_at'=>now()->addDay(),
        ], ...$overrides]);
    }
}
