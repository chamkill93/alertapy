<?php

namespace Tests\Feature;

use App\Collectors\PressHomepageCollector;
use App\Collectors\RegionalPressCollector;
use App\Models\Source;
use App\Services\SourceCommandRegistry;
use Database\Seeders\AdditionalPressSourceSeeder;
use Database\Seeders\RegionalPressSourceSeeder;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegionalPressSourceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_regional_publishers_are_seeded_as_bounded_automatic_collectors(): void
    {
        $this->seed([
            SourceSeeder::class,
            AdditionalPressSourceSeeder::class,
            RegionalPressSourceSeeder::class,
        ]);

        $definitions = RegionalPressSourceSeeder::definitions();
        $slugs = array_column($definitions, 'slug');

        $this->assertCount(27, $definitions);
        $this->assertContains('oviedopress', $slugs);
        $this->assertContains('mas-encarnacion', $slugs);
        $this->assertContains('tu-radio-925', $slugs);
        $this->assertContains('vision-comunicaciones', $slugs);
        $this->assertContains('itapua-noticias', $slugs);
        $this->assertCount(count($slugs), array_unique($slugs));
        $this->assertSame(count($definitions), Source::query()->whereIn('slug', $slugs)->count());

        $registry = app(SourceCommandRegistry::class)->scrapers();

        foreach ($definitions as $definition) {
            $source = Source::query()->where('slug', $definition['slug'])->sole();

            $this->assertTrue($source->enabled);
            $this->assertSame(30, $source->polling_minutes);
            $this->assertSame(168, $source->configuration_json['max_age_hours']);
            $this->assertSame(18, $source->configuration_json['max_links']);
            $this->assertSame(12, $source->configuration_json['max_candidates']);
            $this->assertArrayHasKey($source->slug, $registry);
            $expectedCollector = $source->configuration_json['collector'] === 'press_homepage'
                ? PressHomepageCollector::class
                : RegionalPressCollector::class;
            $this->assertSame($expectedCollector, $registry[$source->slug]);
            if ($source->slug === 'itapua-noticias') {
                $this->assertSame('Itapúa', $source->configuration_json['regional_department']);
                $this->assertSame(['https://itapuanoticias.tv/'], $source->configuration_json['listing_urls']);
            }
            if ($source->slug === 'tvs-digital') {
                $this->assertSame('Itapúa', $source->configuration_json['regional_department']);
                $this->assertContains('https://tvs.com.py/category/locales/', $source->configuration_json['listing_urls']);
            }
            if ($source->slug === 'portal-amambay') {
                $this->assertSame('Amambay', $source->configuration_json['regional_department']);
                $this->assertSame(['https://portalamambay.com.py/'], $source->configuration_json['listing_urls']);
                $this->assertTrue($source->configuration_json['location_search_article_body']);
            }
            if ($source->slug === 'vision-comunicaciones') {
                $this->assertSame('Guairá', $source->configuration_json['regional_department']);
                $this->assertSame(
                    ['https://www.visioncomunicaciones.com.py/blog/categories/villarrica'],
                    $source->configuration_json['listing_urls'],
                );
                $this->assertFalse($source->configuration_json['prefilter_requires_location']);
                $this->assertTrue($source->configuration_json['location_search_article_body']);
            }
        }
    }

    public function test_reseeding_preserves_operator_disablement_and_custom_configuration(): void
    {
        $definition = RegionalPressSourceSeeder::definitions()[0];
        $customConfiguration = ['collector' => 'regional_press', 'regional_department' => 'Caazapá', 'custom' => true];
        Source::query()->create([
            ...$definition,
            'enabled' => false,
            'configuration_json' => $customConfiguration,
        ]);

        $this->seed(RegionalPressSourceSeeder::class);

        $source = Source::query()->where('slug', $definition['slug'])->sole();
        $this->assertFalse($source->enabled);
        $this->assertSame($customConfiguration, $source->configuration_json);
    }
}

