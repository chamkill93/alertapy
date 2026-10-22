<?php

namespace Tests\Feature;

use App\Enums\LocationAccuracy;
use App\Models\GeocodingCache;
use App\Models\Location;
use App\Services\GeocodingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
    }

    public function test_it_uses_persistent_cache_before_local_or_external_lookup(): void
    {
        GeocodingCache::query()->create([
            'query' => 'Asunción',
            'normalized_query' => 'asuncion',
            'latitude' => -25.28,
            'longitude' => -57.63,
            'precision' => LocationAccuracy::City,
            'provider' => 'local',
        ]);
        Http::fake();

        $result = app(GeocodingService::class)->resolve('  ASUNCIÓN ');

        $this->assertNotNull($result);
        $this->assertSame('local', $result->provider);
        $this->assertSame(LocationAccuracy::City, $result->precision);
        $this->assertSame(1, GeocodingCache::query()->value('hit_count'));
        Http::assertNothingSent();
    }

    public function test_it_uses_local_location_catalog_before_external_provider(): void
    {
        Location::query()->create([
            'type' => 'city',
            'name' => 'Capiatá',
            'normalized_name' => 'capiata',
            'latitude' => -25.35,
            'longitude' => -57.45,
        ]);
        config()->set('alertapy.geocoding.provider', 'nominatim');
        config()->set('alertapy.geocoding.user_agent', 'AlertaPY tests');
        Http::fake();

        $result = app(GeocodingService::class)->resolve('Capiatá');

        $this->assertNotNull($result);
        $this->assertSame('local', $result->provider);
        $this->assertSame(LocationAccuracy::City, $result->precision);
        $this->assertDatabaseHas('geocoding_cache', ['normalized_query' => 'capiata']);
        Http::assertNothingSent();
    }

    public function test_external_lookup_is_restricted_to_paraguay_and_cached(): void
    {
        $location = Location::query()->create([
            'type' => 'city',
            'name' => 'Capiatá',
            'normalized_name' => 'capiata',
            'geocoding_query' => 'Capiatá',
            'normalized_query' => 'capiata',
            'city' => 'Capiatá',
            'department' => 'Central',
            'accuracy' => 'city',
            'enabled' => true,
        ]);
        config()->set('alertapy.geocoding.provider', 'nominatim');
        config()->set('alertapy.geocoding.user_agent', 'AlertaPY tests');
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '-25.3500', 'lon' => '-57.4500', 'type' => 'administrative', 'addresstype' => 'city'],
            ]),
        ]);

        $service = app(GeocodingService::class);
        $first = $service->resolve('Capiatá');
        $second = $service->resolve('Capiatá');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame('nominatim', $first->provider);
        $this->assertSame(LocationAccuracy::City, $first->precision);
        $this->assertDatabaseCount('geocoding_cache', 1);
        $this->assertSame(-25.35, $location->fresh()->latitude);
        $this->assertSame(-57.45, $location->fresh()->longitude);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['countrycodes'] === 'py'
            && $request['q'] === 'Capiatá, Paraguay');
    }

    public function test_external_miss_is_temporarily_cached_to_prevent_repeated_queries(): void
    {
        config()->set('alertapy.geocoding.provider', 'nominatim');
        config()->set('alertapy.geocoding.user_agent', 'AlertaPY tests');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

        $service = app(GeocodingService::class);

        $this->assertNull($service->resolve('Lugar no encontrado'));
        $this->assertNull($service->resolve('Lugar no encontrado'));
        Http::assertSentCount(1);
    }
}
