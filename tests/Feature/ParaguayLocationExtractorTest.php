<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\LocationAlias;
use App\Services\ParaguayLocationExtractor;
use Database\Seeders\LocationSeeder;
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

    public function test_it_resolves_localities_from_a_current_itapua_report(): void
    {
        $this->seed(LocationSeeder::class);

        $locations = app(ParaguayLocationExtractor::class)->extractAll(
            'El temporal afectó al sur de Itapúa en Coronel Bogado y General Delgado.',
        );

        $this->assertSame(['Coronel Bogado', 'General Delgado'], collect($locations)->pluck('city')->all());
        $this->assertTrue(collect($locations)->every(fn (array $location): bool => $location['department'] === 'Itapúa'
            && $location['accuracy'] === 'city'));
        $this->assertEqualsWithDelta(-27.16293, (float) Location::query()->where('name', 'Coronel Bogado')->value('latitude'), 0.00001);
        $this->assertEqualsWithDelta(-56.24023, (float) Location::query()->where('name', 'Coronel Bogado')->value('longitude'), 0.00001);
        $this->assertEqualsWithDelta(-27.09046, (float) Location::query()->where('name', 'General Delgado')->value('latitude'), 0.00001);
        $this->assertEqualsWithDelta(-56.52474, (float) Location::query()->where('name', 'General Delgado')->value('longitude'), 0.00001);
    }

    public function test_it_resolves_department_wide_notices_for_all_seventeen_departments(): void
    {
        $this->seed(LocationSeeder::class);

        $expectedDepartments = [
            'Alto Paraná', 'Alto Paraguay', 'Amambay', 'Boquerón', 'Caaguazú', 'Caazapá', 'Canindeyú',
            'Central', 'Concepción', 'Cordillera', 'Guairá', 'Itapúa', 'Misiones', 'Ñeembucú',
            'Paraguarí', 'Presidente Hayes', 'San Pedro',
        ];
        $departmentLocations = Location::query()
            ->where('type', 'department')
            ->pluck('department')
            ->all();

        $this->assertEqualsCanonicalizing($expectedDepartments, $departmentLocations);

        $result = app(ParaguayLocationExtractor::class)->extractAll(
            'Avisos en el Departamento de Concepción, Departamento de Guairá y Departamento de Paraguarí.',
        );

        $this->assertSame(['Concepción', 'Guairá', 'Paraguarí'], collect($result)->pluck('department')->all());
        $this->assertTrue(collect($result)->every(fn (array $location): bool => $location['city'] === null
            && $location['accuracy'] === 'department'));
    }
}

