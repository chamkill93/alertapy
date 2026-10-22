<?php

namespace Tests\Unit;

use App\Services\ParaguayRoadLocationParser;
use App\Services\RoadNameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ParaguayRoadLocationParserTest extends TestCase
{
    #[DataProvider('roadCases')]
    public function test_it_parses_real_road_expressions(string $text, string $road, ?float $km): void
    {
        $result = (new ParaguayRoadLocationParser(new RoadNameNormalizer))->parse($text); $this->assertNotNull($result); $this->assertSame($road, $result->roadCode); $this->assertSame($km, $result->kilometer);
    }
    public static function roadCases(): array { return [['Ruta PY02 km 26','PY02',26.0],['PY02 km 26','PY02',26.0],['Ruta PY 02, Km. 26','PY02',26.0],['Ruta 2 km 26','PY02',26.0],['Ruta PY05 km 51','PY05',51.0],['PY06 kilómetro 80','PY06',80.0],['sobre la PY02 en Pedrozo','PY02',null],['a la altura del km 175 de la PY01','PY01',175.0],['PY03 y acceso a Limpio','PY03',null]]; }

    public function test_route_only_and_ambiguous_offset_never_invent_a_point(): void
    {
        $parser = new ParaguayRoadLocationParser(new RoadNameNormalizer); $route = $parser->parse('Accidente sobre PY02'); $this->assertSame('PY02', $route->roadCode); $this->assertNull($route->kilometer); $this->assertNull($route->latitude);
        $offset = $parser->parse('300 metros antes del km 175 sobre la PY01'); $this->assertSame(-300, $offset->relativeOffsetM); $this->assertTrue($offset->directionUnknown); $this->assertNull($offset->latitude);
    }
}
