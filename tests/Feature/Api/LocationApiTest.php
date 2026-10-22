<?php

namespace Tests\Feature\Api;

use App\Models\Location;
use App\Models\LocationAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_searches_the_local_location_catalog_without_external_requests(): void
    {
        Location::query()->create([
            'type' => 'city',
            'name' => 'Asunción',
            'normalized_name' => 'asuncion',
            'latitude' => -25.2867,
            'longitude' => -57.3333,
        ]);

        $response = $this->getJson('/api/locations?query=asuncion');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Asunción')
            ->assertJsonPath('data.0.type', 'city');
    }

    public function test_location_search_requires_at_least_two_characters(): void
    {
        $this->getJson('/api/locations?query=a')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('query');
    }

    public function test_it_searches_locations_by_database_alias(): void
    {
        $location = Location::query()->create([
            'type' => 'city',
            'name' => 'Mariscal Estigarribia',
            'normalized_name' => 'mariscal estigarribia',
            'city' => 'Mariscal Estigarribia',
            'department' => 'Boquerón',
            'accuracy' => 'city',
            'enabled' => true,
        ]);
        LocationAlias::query()->create([
            'location_id' => $location->id,
            'alias' => 'Mcal. Estigarribia',
            'normalized_alias' => 'mcal. estigarribia',
        ]);

        $this->getJson('/api/locations?query=mcal')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Mariscal Estigarribia')
            ->assertJsonPath('data.0.department', 'Boquerón');
    }
}
