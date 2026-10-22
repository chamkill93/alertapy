<?php

namespace Tests\Feature;

use App\DTOs\CandidateDTO;
use App\DTOs\GeocodingResult;
use App\Enums\LocationAccuracy;
use App\Enums\SourceType;
use App\Models\Source;
use App\Models\Category;
use App\Models\Incident;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Services\CorroborationService;
use App\Services\ClosingRuleEngine;
use App\Services\EvidenceScorer;
use App\Services\ParserStructureMonitor;
use App\Services\RuleBasedDecisionProvider;
use App\Services\SourceHealthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecisionAndHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_engine_publishes_allowed_sources_waits_discovery_and_rejects_unsafe_evidence(): void
    {
        config()->set('alertapy.ingestion.local_rules_publish', true); $provider = new RuleBasedDecisionProvider(new EvidenceScorer, new ClosingRuleEngine); $location = new GeocodingResult(-25.3,-57.6,LocationAccuracy::City,'local','Central');
        $candidate = fn (string $title, ?string $url='https://example.com/a') => new CandidateDTO('source',SourceType::Press,'1',$title,$url ?? '',$title,CarbonImmutable::now(),detectedLocation:'Central');
        $official = new Source(['name'=>'Oficial','slug'=>'source','type'=>SourceType::Official,'base_url'=>'https://example.com','domain'=>'example.com']); $press = new Source(['name'=>'Prensa','slug'=>'source','type'=>SourceType::Press,'base_url'=>'https://example.com','domain'=>'example.com']); $discovery = new Source(['name'=>'Discovery','slug'=>'source','type'=>SourceType::Discovery,'base_url'=>'https://example.com','domain'=>'example.com']);
        $this->assertSame('PUBLISH',$provider->decide($official,$candidate('Corte en Central'),$location)->action); $this->assertSame('PUBLISH',$provider->decide($press,$candidate('Accidente en Central'),$location)->action); $this->assertSame('WAIT',$provider->decide($discovery,$candidate('Accidente en Central'),$location)->action); $this->assertSame('REJECT',$provider->decide($press,$candidate('Acusado Juan Pérez es culpable'),$location)->action); $this->assertSame('REJECT',$provider->decide($press,$candidate('Accidente', ''),$location)->action); $this->assertSame('CLOSE',$provider->decide($official,$candidate('Servicio normalizado en Central'),$location)->action);
    }

    public function test_parser_monitor_and_health_detect_unexpected_empty_structure_and_disabled_configuration(): void
    {
        $source = Source::query()->create(['name'=>'Fuente','slug'=>'fuente','type'=>SourceType::Official,'base_url'=>'https://example.com','domain'=>'example.com','enabled'=>true,'polling_minutes'=>5]); $monitor = new ParserStructureMonitor;
        $monitor->observe($source,str_repeat('<article>dato</article>',10),10,'v1'); $snapshot = $monitor->observe($source,'',0,'v1'); $this->assertTrue($snapshot->structure_changed); $this->assertSame('DEGRADED',(new SourceHealthService)->status($source->fresh())['status']);
        $source->update(['enabled'=>false,'configuration_json'=>['inactive_reason'=>'DISABLED_CONFIGURATION: falta token']]); $this->assertSame('DISABLED_CONFIGURATION',(new SourceHealthService)->status($source->fresh())['status']);
    }

    public function test_health_distinguishes_healthy_http_degraded_and_repeated_failure(): void
    {
        $health = new SourceHealthService; $source = Source::query()->create(['name'=>'HTTP','slug'=>'http','type'=>SourceType::Official,'base_url'=>'https://example.com','domain'=>'example.com','enabled'=>true,'polling_minutes'=>5,'last_run_at'=>now(),'last_success_at'=>now(),'last_http_status'=>200]);
        $this->assertSame('HEALTHY',$health->status($source->fresh())['status']);
        $source->update(['last_http_status'=>403,'last_error'=>'HTTP 403']); $this->assertSame('DEGRADED',$health->status($source->fresh())['status']);
        $source->update(['last_http_status'=>503,'consecutive_errors'=>3]); $this->assertSame('FAILING',$health->status($source->fresh())['status']);
        $source->update(['enabled'=>false,'configuration_json'=>null]); $this->assertSame('DISABLED',$health->status($source->fresh())['status']);
    }

    public function test_road_and_kilometer_require_event_identity_before_corroborating_different_headlines(): void
    {
        $category = Category::query()->create(['name'=>'Tránsito','slug'=>'traffic_accident','icon'=>'traffic','color'=>'#f00','enabled'=>true,'sort_order'=>1]);
        $existing = Incident::query()->create(['category_id'=>$category->id,'title'=>'Colisión vehicular','summary'=>'Hecho vial','source_type'=>SourceType::Press,'verification_level'=>'press','status'=>IncidentStatus::Reported,'severity'=>Severity::Medium,'latitude'=>-25.3,'longitude'=>-57.6,'location_accuracy'=>LocationAccuracy::RouteKmInterpolated,'location_text'=>'PY02 km 26','road_code'=>'PY02','road_kilometer'=>26,'starts_at'=>now(),'published_at'=>now()]);
        $source = Source::query()->create(['name'=>'NPY','slug'=>'npy-test','type'=>SourceType::Press,'base_url'=>'https://example.com','domain'=>'example.com','enabled'=>true,'polling_minutes'=>5]);
        $candidate = new CandidateDTO('npy-test',SourceType::Press,'x','Accidente en Ruta 2, kilómetro 26','https://example.com/x','Dos vehículos involucrados',CarbonImmutable::now());
        $farButSameRoad = new GeocodingResult(-25.55,-57.6,LocationAccuracy::City,'local','otra');
        $match = (new CorroborationService)->findMatch($category->id,$candidate,$farButSameRoad,CarbonImmutable::now(),null,null,$source);
        $this->assertNull($match, 'El mismo kilómetro no identifica por sí solo un accidente.');
        $existing->update(['title' => 'Colisión entre camión y motocicleta']);
        $candidate = new CandidateDTO('npy-test',SourceType::Press,'x','Choque entre camión y motocicleta en Ruta 2, kilómetro 26','https://example.com/x','Dos vehículos involucrados',CarbonImmutable::now());
        $match = (new CorroborationService)->findMatch($category->id,$candidate,$farButSameRoad,CarbonImmutable::now(),null,null,$source);
        $this->assertSame($existing->id,$match?->id);
    }

    public function test_closing_engine_requires_temporal_and_territorial_match(): void
    {
        $category = Category::query()->create(['name'=>'Agua','slug'=>'water','icon'=>'water','color'=>'#00f','enabled'=>true,'sort_order'=>1]);
        $incident = Incident::query()->create(['category_id'=>$category->id,'title'=>'Servicio interrumpido en planta San Miguel','summary'=>'Sin agua','source_type'=>SourceType::Official,'verification_level'=>'official','status'=>IncidentStatus::Active,'severity'=>Severity::Medium,'latitude'=>-25.3,'longitude'=>-57.6,'location_accuracy'=>LocationAccuracy::City,'location_text'=>'Capiatá','city'=>'Capiatá','starts_at'=>now()->subHour(),'published_at'=>now()->subHour()]);
        $engine = new ClosingRuleEngine; $this->assertSame($incident->id,$engine->findMatching($category->id,'Capiatá','Central',now(),'Servicio normalizado en planta San Miguel')?->id); $this->assertNull($engine->findMatching($category->id,'Encarnación','Itapúa',now(),'Servicio normalizado en planta San Miguel'));
        $this->assertNull($engine->findMatching($category->id,'Capiatá','Central',now(),'Servicio normalizado'));
    }
}
