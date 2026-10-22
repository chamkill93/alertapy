<?php

namespace Tests\Unit\Collectors;

use App\Services\Collectors\AndeHtmlParser;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AndeHtmlParserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
    }

    public function test_it_splits_each_real_ande_zone_into_an_independent_candidate(): void
    {
        $parser = app(AndeHtmlParser::class);
        $links = $parser->articleLinks(
            file_get_contents(base_path('tests/Fixtures/ande-index.html')),
            'https://www.ande.gov.py',
        );

        $this->assertCount(1, $links);
        $this->assertSame('15471', $links[0]['id']);

        $candidates = $parser->articleCandidates(
            file_get_contents(base_path('tests/Fixtures/ande-detail.html')),
            'https://www.ande.gov.py/interna.php?id=15471',
            '15471',
        );

        $this->assertCount(2, $candidates);
        $this->assertSame('15471-zone-01', $candidates[0]->externalIdentifier);
        $this->assertSame('electricity', $candidates[0]->metadata['category_slug']);
        $this->assertSame('2026-08-18T07:30:00-03:00', $candidates[0]->metadata['starts_at']);
        $this->assertSame('Caacupé, Cordillera', $candidates[0]->detectedLocation);
    }

    public function test_it_prioritizes_the_municipality_at_the_end_of_an_ande_zone(): void
    {
        $candidates = app(AndeHtmlParser::class)->articleCandidates(
            '<h1>Trabajos programados para el 18 de agosto de 2026</h1>
             ZONA 01: Santa Cruz e Isla Po’i. Barrio Itá Ka’aguy - Fernando de la Mora.
             HORARIO DE TRABAJO: De 08:00 a 12:00 horas.
             ACTIVIDAD: Cambio de conductor.
             UNIDAD RESPONSABLE: ANDE.',
            'https://www.ande.gov.py/interna.php?id=99999',
            '99999',
        );

        $this->assertCount(1, $candidates);
        $this->assertSame('Fernando de la Mora', $candidates[0]->detectedLocation);
    }
}
