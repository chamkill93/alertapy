<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Incident;
use App\Models\Source;
use App\Collectors\HealthNewsCollector;
use App\Services\SourceCommandRegistry;
use Database\Seeders\AdditionalPressSourceSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_create_only_reference_catalogs_and_never_demo_incidents(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(15, Category::query()->count());
        $this->assertSame(89, Source::query()->count());
        $this->assertDatabaseHas('sources', ['slug' => '1000noticias', 'enabled' => true, 'ingestion_type' => 'api']);
        $this->assertDatabaseHas('sources', ['slug' => 'nanduti', 'enabled' => true, 'ingestion_type' => 'api']);
        $this->assertDatabaseHas('sources', ['slug' => 'latribuna', 'enabled' => true]);
        $this->assertSame(168, config('alertapy.sources.latribuna.max_age_hours'));
        foreach (['abc', 'ultimahora', 'lanacion', 'hoy', 'latribuna'] as $utilityPressSource) {
            $this->assertContains($utilityPressSource, config('alertapy.cycle.priority_sources'));
        }
        $this->assertDatabaseHas('sources', ['slug' => 'dmh-automatic-stations', 'enabled' => true]);
        $this->assertDatabaseHas('sources', ['slug' => 'dmh-cap', 'enabled' => true]);
        $this->assertDatabaseHas('sources', ['slug' => 'pai', 'enabled' => true, 'ingestion_type' => 'html']);
        $this->assertDatabaseHas('sources', ['slug' => 'digies', 'enabled' => true, 'ingestion_type' => 'html']);
        $this->assertSame('health', Source::query()->where('slug', 'pai')->sole()->configuration_json['category_classifier']);
        $this->assertSame(HealthNewsCollector::class, app(SourceCommandRegistry::class)->scrapers()['pai']);
        $this->assertContains('pai', config('alertapy.cycle.priority_sources'));
        $this->assertContains('digies', config('alertapy.cycle.priority_sources'));
        $additionalPress = Source::query()->whereIn('slug', [
            'asuncion-actual', 'ahora-paraguay', 'elnacional', 'la-clave', 'ratio-paraguay', 'ahendu', 'la-verdad-paraguaya',
            'el-independiente', 'parametro', 'pulso-paraguayo',
        ])->get();
        $this->assertCount(10, $additionalPress);
        $originalPress = $additionalPress->whereIn('slug', ['asuncion-actual', 'ahora-paraguay', 'elnacional', 'la-clave', 'ratio-paraguay']);
        $this->assertTrue($originalPress->every(fn (Source $source): bool => $source->enabled
            && $source->polling_minutes === 15
            && ($source->configuration_json['location_search_article_body'] ?? null) === false));
        $territorialPress = $additionalPress->whereIn('slug', [
            'ahendu', 'la-verdad-paraguaya', 'el-independiente', 'parametro', 'pulso-paraguayo',
        ]);
        $this->assertTrue($territorialPress->every(fn (Source $source): bool => $source->enabled
            && $source->polling_minutes === 30
            && ($source->configuration_json['location_search_article_body'] ?? null) === true
            && ($source->configuration_json['prefilter_requires_location'] ?? null) === false));
        $this->assertSame([], array_diff($additionalPress->pluck('slug')->all(), config('alertapy.cycle.priority_sources')));
        $this->assertDatabaseHas('sources', ['slug' => 'ultimas-noticias-cde', 'enabled' => true]);
        $this->assertDatabaseHas('sources', ['slug' => 'hechos-digital', 'enabled' => true]);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_every_enabled_catalog_source_is_registered_in_the_automatic_cycle(): void
    {
        $this->seed(DatabaseSeeder::class);

        $registered = array_keys(app(SourceCommandRegistry::class)->commands());
        $priorities = (array) config('alertapy.cycle.priority_sources');
        $this->assertLessThanOrEqual(config('alertapy.cycle.max_sources'), count($priorities));
        $this->assertSame([], array_values(array_diff($priorities, $registered)), 'Priority sources missing from the master cycle.');
        $unregistered = Source::query()->where('enabled', true)
            ->whereNotIn('slug', $registered)
            ->orderBy('slug')
            ->pluck('slug')
            ->all();

        $this->assertSame([], $unregistered, 'Enabled sources missing from the master cycle: '.implode(', ', $unregistered));
    }

    public function test_additional_press_seeder_preserves_operator_settings_and_disabled_sources(): void
    {
        $definition = AdditionalPressSourceSeeder::definitions()[0];
        $definition['enabled'] = false;
        $definition['polling_minutes'] = 45;
        $definition['configuration_json'] = [
            'collector' => 'press_homepage',
            'operator_note' => 'Keep this setting',
        ];
        Source::query()->create($definition);

        $this->seed(AdditionalPressSourceSeeder::class);
        $this->seed(AdditionalPressSourceSeeder::class);

        $source = Source::query()->where('slug', 'asuncion-actual')->sole();
        $this->assertFalse($source->enabled);
        $this->assertSame(45, $source->polling_minutes);
        $this->assertSame('Keep this setting', $source->configuration_json['operator_note']);
        $this->assertFalse($source->configuration_json['location_search_article_body']);
        $this->assertSame(10, Source::query()->count());
    }
}

