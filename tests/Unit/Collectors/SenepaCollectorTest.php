<?php

namespace Tests\Unit\Collectors;

use App\Collectors\SenepaCollector;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SenepaCollectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        CarbonImmutable::setTestNow('2026-08-20 19:00:00 America/Asuncion');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_it_uses_the_official_api_and_keeps_only_geolocatable_vector_actions(): void
    {
        $source = Source::query()->create([
            'name'=>'SENEPA', 'slug'=>'senepa', 'type'=>SourceType::Official,
            'base_url'=>'https://senepa.gov.py/', 'domain'=>'senepa.gov.py',
            'enabled'=>true, 'polling_minutes'=>30,
        ]);
        Http::fake([
            'senepa.gov.py/wp-json/wp/v2/posts*' => Http::response([
                $this->postData(11382, '2026-08-20T11:55:15', 'Cambyretá fortalece la prevención de arbovirosis ante el Rally del Paraguay 2026', 'SENEPA realizó una minga ambiental y eliminación de criaderos en Cambyretá, Itapúa.'),
                $this->postData(11370, '2026-08-20T07:00:00', '20 de agosto: Día Mundial del Mosquito', 'Recordación mundial sin una intervención territorial informada.'),
                $this->postData(11360, '2026-08-19T09:50:03', 'Operativo de prevención de arbovirosis en Encarnación', 'Rastrillaje y control químico en barrios de Encarnación, Itapúa.'),
                $this->postData(11350, '2026-08-19T08:00:00', 'SENEPA fortalece acuerdos éticos MECIP en Asunción', 'Actividad institucional en Asunción.'),
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $candidates = app(SenepaCollector::class)->collect($source);

        $this->assertCount(2, $candidates);
        $this->assertSame(['Cambyretá', 'Encarnación'], $candidates->pluck('metadata.city')->all());
        $this->assertSame(['health_vector', 'health_vector'], $candidates->pluck('metadata.category_slug')->all());
        $this->assertSame(['senepa-11382', 'senepa-11360'], $candidates->pluck('externalIdentifier')->all());
    }

    public function test_dengue_outbreak_is_correlated_with_public_health_and_vector_layers(): void
    {
        $source = Source::query()->create([
            'name'=>'SENEPA', 'slug'=>'senepa', 'type'=>SourceType::Official,
            'base_url'=>'https://senepa.gov.py/', 'domain'=>'senepa.gov.py',
            'enabled'=>true, 'polling_minutes'=>30,
        ]);
        $title = 'Confirman brote de dengue en Villarrica';
        $storyUrl = 'https://senepa.gov.py/2026/08/20/brote-dengue-villarrica/';
        Http::preventStrayRequests();
        Http::fake([
            'senepa.gov.py/wp-json/wp/v2/posts*' => Http::response([
                [
                    'id'=>11390, 'date'=>'2026-08-20T10:00:00', 'link'=>$storyUrl,
                    'title'=>['rendered'=>$title],
                    'excerpt'=>['rendered'=>'La vigilancia epidemiológica confirmó casos de dengue en Villarrica, Guairá.'],
                    'content'=>['rendered'=>'La vigilancia epidemiológica confirmó casos de dengue en Villarrica, Guairá.'],
                ],
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $candidates = app(SenepaCollector::class)->collect($source);

        $this->assertSame(['health_vector', 'health_public'], $candidates->pluck('metadata.category_slug')->all());
        $this->assertSame(['senepa-11390', 'senepa-11390-health_public'], $candidates->pluck('externalIdentifier')->all());
        $this->assertSame([false, true], $candidates->pluck('metadata.category_variant')->all());
        $this->assertTrue($candidates->every(fn ($candidate): bool => $candidate->originalUrl === $storyUrl));
        $this->assertSame(['Villarrica', 'Villarrica'], $candidates->pluck('metadata.city')->all());
    }

    public function test_unlocated_vector_notice_uses_asuncion_as_an_explicit_map_reference(): void
    {
        $source = new Source(['name' => 'SENEPA', 'slug' => 'senepa', 'type' => SourceType::Official]);
        Http::fake([
            'senepa.gov.py/wp-json/wp/v2/posts*' => Http::response([[
                'id' => 11400,
                'date' => '2026-08-20T10:00:00',
                'link' => 'https://senepa.gov.py/2026/08/20/operativo-prevencion-arbovirosis/',
                'title' => ['rendered' => 'Operativo de prevención de arbovirosis'],
                'excerpt' => ['rendered' => 'El operativo incluye fumigación y eliminación de criaderos.'],
                'content' => ['rendered' => 'SENEPA realiza control vectorial y eliminación de criaderos.'],
            ]], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $candidate = app(SenepaCollector::class)->collect($source)->sole();

        $this->assertSame('Asunción', $candidate->detectedLocation);
        $this->assertSame(-25.2800459, $candidate->latitude);
        $this->assertSame(-57.6343814, $candidate->longitude);
        $this->assertTrue($candidate->metadata['location_is_fallback']);
        $this->assertNull($candidate->metadata['city']);
        $this->assertNull($candidate->metadata['department']);
    }

    /** @return array<string, mixed> */
    private function postData(int $id, string $date, string $title, string $content): array
    {
        return [
            'id'=>$id, 'date'=>$date, 'link'=>"https://senepa.gov.py/2026/08/20/noticia-{$id}/",
            'title'=>['rendered'=>$title], 'excerpt'=>['rendered'=>$content], 'content'=>['rendered'=>$content],
        ];
    }
}

