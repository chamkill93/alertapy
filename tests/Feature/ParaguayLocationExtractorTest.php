<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\LocationAlias;
use App\Services\ParaguayLocationExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParaguayLocationExtractorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_extracts_a_database_alias_and_returns_structured_map_data(): void
    {
        $location = Location::query()->create([
            'type' => 'city',
            'name' => 'Mariscal Estigarribia',
            'normalized_name' => 'mariscal estigarribia',
            'geocoding_query' => 'Mariscal Estigarribia, Boquerón',
            'normalized_query' => 'mariscal estigarribia, boqueron',
            'city' => 'Mariscal Estigarribia',
            'department' => 'Boquerón',
            'accuracy' => 'city',
            'enabled' => true,
        ]);
        LocationAlias::query()->create([
            'location_id' => $location->id,
            'alias' => 'Mcal. Estigarribia',
            'normalized_alias' => 'mcal. estigarribia',
            'priority' => 50,
        ]);

        $result = app(ParaguayLocationExtractor::class)->extract(
            'Corte de ruta en Mcal. Estigarribia por obras',
        );

        $this->assertSame([
            'text' => 'Mariscal Estigarribia',
            'query' => 'Mariscal Estigarribia, Boquerón',
            'city' => 'Mariscal Estigarribia',
            'department' => 'Boquerón',
            'accuracy' => 'city',
        ], $result);
    }

    public function test_it_ignores_disabled_database_locations(): void
    {
        $location = Location::query()->create([
            'type' => 'city',
            'name' => 'Lugar deshabilitado',
            'normalized_name' => 'lugar deshabilitado',
            'enabled' => false,
        ]);
        LocationAlias::query()->create([
            'location_id' => $location->id,
            'alias' => 'Lugar deshabilitado',
            'normalized_alias' => 'lugar deshabilitado',
        ]);

        $this->assertNull(app(ParaguayLocationExtractor::class)->extract('Alerta en Lugar deshabilitado'));
    }
}
