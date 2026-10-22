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

    /** @return array<string, mixed> */
    private function postData(int $id, string $date, string $title, string $content): array
    {
        return [
            'id'=>$id, 'date'=>$date, 'link'=>"https://senepa.gov.py/2026/08/20/noticia-{$id}/",
            'title'=>['rendered'=>$title], 'excerpt'=>['rendered'=>$content], 'content'=>['rendered'=>$content],
        ];
    }
}
