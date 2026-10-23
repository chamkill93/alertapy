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

        $this->assertCount(18, $definitions);
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

