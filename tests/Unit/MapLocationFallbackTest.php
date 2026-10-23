<?php

namespace Tests\Unit;

use App\Enums\SourceType;
use App\Services\MapLocationFallback;
use Tests\TestCase;

class MapLocationFallbackTest extends TestCase
{
    public function test_it_uses_asuncion_only_as_a_reference_for_unlocated_news(): void
    {
        $fallback = app(MapLocationFallback::class);

        $press = $fallback->forNews(SourceType::Press, null);
        $this->assertSame(MapLocationFallback::ASUNCION_LATITUDE, $press['latitude']);
        $this->assertSame(MapLocationFallback::ASUNCION_LONGITUDE, $press['longitude']);
        $this->assertNull($press['city']);
        $this->assertNull($press['department']);
        $this->assertTrue($press['is_fallback']);

        $official = $fallback->forNews(SourceType::Official, null);
        $this->assertSame(MapLocationFallback::ASUNCION_LATITUDE, $official['latitude']);
        $this->assertSame(MapLocationFallback::ASUNCION_LONGITUDE, $official['longitude']);
        $this->assertTrue($official['is_fallback']);
        $this->assertNull($fallback->forNews(SourceType::Sensor, null));
        $this->assertFalse($fallback->forNews(SourceType::Press, [
            'text' => 'Encarnación',
            'query' => 'Encarnación',
            'city' => 'Encarnación',
            'department' => 'Itapúa',
            'accuracy' => 'city',
        ])['is_fallback']);
    }

    public function test_opinion_articles_are_recognized_as_editorial(): void
    {
        $this->assertTrue(app(MapLocationFallback::class)->isClearlyEditorial('Columna de opinión', 'Análisis de opinión'));
        $this->assertFalse(app(MapLocationFallback::class)->isClearlyEditorial('Inundación obliga a evacuar viviendas'));
    }
}
