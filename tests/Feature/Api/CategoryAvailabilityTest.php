<?php

namespace Tests\Feature\Api;

use App\Enums\IncidentStatus;
use App\Enums\LocationAccuracy;
use App\Enums\Severity;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Incident;
use App\Models\IncidentSource;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_selecting_one_category_keeps_other_counts_and_reports_empty_categories(): void
    {
        $electricity = $this->category('electricity');
        $fire = $this->category('fire');
        $this->category('air_quality');
        $this->incident($electricity);
        $this->incident($fire);
        $this->incident($fire);

        $response = $this->getJson('/api/incidents?categories=electricity&per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category.slug', 'electricity')
            ->assertJsonCount(1, 'summary')
            ->assertJsonPath('summary.0.slug', 'electricity')
            ->assertJsonPath('summary.0.count', 1)
            ->assertJsonCount(3, 'category_availability');
        $expected = ['air_quality' => 0, 'electricity' => 1, 'fire' => 2];
        $this->assertSame($expected, collect($response->json('category_availability'))->pluck('count', 'slug')->all());

        $all = $this->getJson('/api/incidents?per_page=1&pagination=cursor')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($expected, collect($all->json('category_availability'))->pluck('count', 'slug')->all());
    }

    public function test_selecting_no_categories_preserves_availability_without_displaying_incidents(): void
    {
        $fire = $this->category('fire');
        $this->category('water');
        $this->incident($fire);

        $this->getJson('/api/incidents?categories=__none__')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonCount(0, 'summary')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('category_availability', [
                ['slug' => 'fire', 'count' => 1],
                ['slug' => 'water', 'count' => 0],
            ]);
    }

    public function test_availability_respects_time_bbox_source_city_status_road_and_publication_filters(): void
    {
        $this->freezeTime();
        $electricity = $this->category('electricity');
        $fire = $this->category('fire');
        $this->incident($electricity);
        $this->incident($fire);
        foreach ([
            ['published_at' => now()->subDays(2)],
            ['latitude' => -26.0],
            ['source_type' => SourceType::Press],
            ['city' => 'Encarnación'],
            ['status' => IncidentStatus::Confirmed],
            ['road_code' => 'PY01'],
            ['published_at' => null],
            ['published_at' => now()->addHour()],
        ] as $excluded) {
            $this->incident($fire, $excluded);
        }

        $response = $this->getJson('/api/incidents?categories=electricity&period=24h&bbox=-25.4,-57.7,-25.2,-57.5&source=official&city=Asunci%C3%B3n&status=active&road=PY02')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame(['electricity' => 1, 'fire' => 1], collect($response->json('category_availability'))->pluck('count', 'slug')->all());
    }

    public function test_availability_respects_specific_source_and_radius_even_with_no_category_selected(): void
    {
        $fire = $this->category('fire');
        $source = Source::query()->create([
            'name' => 'Fuente', 'slug' => 'specific-source', 'type' => SourceType::Official,
            'base_url' => 'https://example.test', 'domain' => 'example.test', 'enabled' => true, 'polling_minutes' => 5,
        ]);
        $near = $this->incident($fire);
        $outsideRadius = $this->incident($fire, ['latitude' => -25.32]);
        $this->incident($fire); // Inside the radius, but not published by the requested source.
        foreach ([$near, $outsideRadius] as $incident) {
            IncidentSource::query()->create([
                'incident_id' => $incident->id, 'source_id' => $source->id,
                'original_title' => $incident->title, 'original_url' => 'https://example.test/'.$incident->id,
                'published_at' => $incident->published_at, 'detected_at' => now(),
            ]);
        }

        $this->getJson('/api/incidents?categories=__none__&source=specific-source&bbox=-25.4,-57.7,-25.2,-57.5&near_lat=-25.30&near_lng=-57.60&radius_km=1')
            ->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('category_availability', [['slug' => 'fire', 'count' => 1]]);
    }

    public function test_empty_result_still_contains_all_catalog_categories_with_zero_counts(): void
    {
        $this->category('air_quality');
        $this->category('water');

        $this->getJson('/api/incidents?period=now')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonCount(0, 'summary')
            ->assertJsonPath('category_availability', [
                ['slug' => 'air_quality', 'count' => 0],
                ['slug' => 'water', 'count' => 0],
            ]);
    }

    private function category(string $slug): Category
    {
        return Category::query()->create(['slug' => $slug, 'name' => $slug, 'icon' => 'test', 'color' => '#123456', 'enabled' => true, 'sort_order' => 1]);
    }

    private function incident(Category $category, array $overrides = []): Incident
    {
        return Incident::query()->create([
            'category_id' => $category->id, 'title' => 'Incidencia de prueba', 'summary' => 'Solo dentro de PHPUnit',
            'source_type' => SourceType::Official, 'verification_level' => 'official', 'status' => IncidentStatus::Active,
            'severity' => Severity::Medium, 'latitude' => -25.30, 'longitude' => -57.60,
            'location_accuracy' => LocationAccuracy::City, 'department' => 'Capital', 'city' => 'Asunción',
            'location_text' => 'Asunción', 'road_code' => 'PY02', 'starts_at' => now()->subMinutes(15),
            'expires_at' => now()->addHour(), 'published_at' => now()->subMinutes(10), ...$overrides,
        ]);
    }
}
