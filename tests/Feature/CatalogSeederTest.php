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
        $this->assertSame(108, Source::query()->count());
        $this->assertTrue(config('alertapy.discovery.gdelt.enabled'));
        $this->assertCount(5, config('alertapy.discovery.gdelt.queries'));
        $this->assertDatabaseHas('sources', ['slug' => 'gdelt', 'enabled' => true]);
        $gdelt = Source::query()->where('slug', 'gdelt')->sole();
        $this->assertSame('GDELT descubre URLs originales para revisión; nunca se muestra como fuente final ni publica incidencias automáticamente.', $gdelt->configuration_json['review_policy']);
        $this->assertDatabaseHas('sources', ['slug' => '1000noticias', 'enabled' => true, 'ingestion_type' => 'api']);
        $this->assertDatabaseHas('sources', ['slug' => 'nanduti', 'enabled' => true, 'ingestion_type' => 'api']);
        $this->assertDatabaseHas('sources', ['slug' => 'latribuna', 'enabled' => true]);
        $this->assertSame(168, config('alertapy.sources.latribuna.max_age_hours'));
        $this->assertContains('https://www.latribuna.com.py/etiqueta/neembucu-2/', config('alertapy.sources.latribuna.listing_urls'));
        $this->assertContains('https://www.latribuna.com.py/etiqueta/paraguari-departamento/', config('alertapy.sources.latribuna.listing_urls'));
        foreach (['abc', 'ultimahora', 'lanacion', 'hoy', 'latribuna'] as $utilityPressSource) {
            $this->assertContains($utilityPressSource, config('alertapy.cycle.priority_sources'));
        }
        $this->assertDatabaseHas('sources', ['slug' => 'dmh-automatic-stations', 'enabled' => true]);
        $this->assertDatabaseHas('sources', ['slug' => 'dmh-cap', 'enabled' => true]);
        $featuredHealth = Source::query()->where('slug', 'mspbs-health-featured')->sole();
        $this->assertTrue($featuredHealth->enabled);
        $this->assertSame('official', $featuredHealth->type->value);
        $this->assertSame('health_news', $featuredHealth->configuration_json['collector']);
        $this->assertSame(
            ['https://www.mspbs.gov.py/buscar.php?modo=0&page=669&q=&url=portal'],
            config('alertapy.sources.mspbs-health-featured.listing_urls'),
        );
        $this->assertSame(HealthNewsCollector::class, app(SourceCommandRegistry::class)->scrapers()['mspbs-health-featured']);
        $this->assertDatabaseHas('sources', ['slug' => 'pai', 'enabled' => true, 'ingestion_type' => 'html']);
        $this->assertDatabaseHas('sources', ['slug' => 'agencia-ip', 'enabled' => true, 'ingestion_type' => 'html']);
        $this->assertDatabaseHas('sources', ['slug' => 'cde-hot', 'enabled' => true, 'ingestion_type' => 'html']);
        $cdeHot = Source::query()->where('slug', 'cde-hot')->sole();
        $this->assertSame('regional_press', $cdeHot->configuration_json['collector']);
        $this->assertSame(15, $cdeHot->polling_minutes);
        $this->assertDatabaseHas('sources', ['slug' => 'gobernacion-neembucu', 'enabled' => true, 'type' => 'official']);
        $neembucuGovernment = Source::query()->where('slug', 'gobernacion-neembucu')->sole();
        $this->assertSame('press_homepage', $neembucuGovernment->configuration_json['collector']);
        $this->assertSame('Ñeembucú', $neembucuGovernment->configuration_json['regional_department']);
        $this->assertSame([
            'https://xn--eembucu-3za.gov.py/noticias/',
            'https://xn--eembucu-3za.gov.py/category/gacetilla-de-prensa/',
        ], $neembucuGovernment->configuration_json['listing_urls']);
        $agenciaIp = Source::query()->where('slug', 'agencia-ip')->sole();
        $this->assertSame('press_homepage', $agenciaIp->configuration_json['collector']);
        $this->assertFalse($agenciaIp->configuration_json['prefilter_requires_location']);
        $this->assertTrue($agenciaIp->configuration_json['location_search_article_body']);
        $this->assertContains('agencia-ip', config('alertapy.cycle.priority_sources'));
        $this->assertDatabaseHas('sources', ['slug' => 'digies', 'enabled' => true, 'ingestion_type' => 'html']);
        $healthSources = Source::query()->whereIn('slug', ['dinavisa', 'digesa', 'ins', 'inan', 'dnerhs'])->get();
        $this->assertCount(5, $healthSources);
        $this->assertTrue($healthSources->every(fn (Source $source): bool => $source->enabled
            && ($source->configuration_json['collector'] ?? null) === 'health_news'
            && ($source->configuration_json['category_classifier'] ?? null) === 'health'));
        $dnerhs = Source::query()->where('slug', 'dnerhs')->sole();
        $this->assertSame('health_news', $dnerhs->configuration_json['collector']);
        $this->assertSame([
            'https://dnerhs.mspbs.gov.py/index.php/category/dnerhs/noticias/',
            'https://dnerhs.mspbs.gov.py/index.php/category/dnerhs/dn/dgprhs/eqips/',
        ], $dnerhs->configuration_json['listing_urls']);
        $this->assertDatabaseHas('sources', ['slug' => 'rdn', 'enabled' => true]);
        $this->assertDatabaseHas('sources', ['slug' => 'diario-paraguayo', 'enabled' => true]);
        $this->assertDatabaseHas('sources', ['slug' => 'paraguay24horas', 'enabled' => true]);
        $this->assertDatabaseHas('sources', ['slug' => 'el-surti', 'enabled' => true]);
        $elSurti = Source::query()->where('slug', 'el-surti')->sole();
        $this->assertSame('press_homepage', $elSurti->configuration_json['collector']);
        $this->assertSame(30, $elSurti->polling_minutes);
        $this->assertSame(['https://elsurti.com/'], $elSurti->configuration_json['listing_urls']);
        $this->assertFalse($elSurti->configuration_json['prefilter_requires_location']);
        $this->assertTrue($elSurti->configuration_json['location_search_article_body']);
        $paraguay24Horas = Source::query()->where('slug', 'paraguay24horas')->sole();
        $this->assertSame(30, $paraguay24Horas->polling_minutes);
        $this->assertSame('https://paraguay24horas.com/', $paraguay24Horas->base_url);
        $this->assertFalse($paraguay24Horas->configuration_json['prefilter_requires_location']);
        $this->assertTrue($paraguay24Horas->configuration_json['location_search_article_body']);
        $diarioParaguayo = Source::query()->where('slug', 'diario-paraguayo')->sole();
        $this->assertSame('diarioparaguayo.com', $diarioParaguayo->domain);
        $this->assertSame('https://www.diarioparaguayo.com.py/', $diarioParaguayo->base_url);
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
        $this->assertDatabaseHas('sources', ['slug' => 'tu-radio-925', 'enabled' => true]);
        $this->assertDatabaseHas('sources', ['slug' => 'agr-fm-tv', 'enabled' => true]);
        $this->assertDatabaseHas('sources', ['slug' => 'canindeyu-digital', 'enabled' => true]);
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
        $this->assertSame(14, Source::query()->count());
    }
}

