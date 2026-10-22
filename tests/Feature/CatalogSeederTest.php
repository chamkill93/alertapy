<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Incident;
use App\Models\Source;
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

        $this->assertSame(14, Category::query()->count());
        $this->assertSame(64, Source::query()->count());
        $this->assertDatabaseHas('sources', ['slug' => '1000noticias', 'enabled' => true, 'ingestion_type' => 'api']);
        $this->assertDatabaseHas('sources', ['slug' => 'nanduti', 'enabled' => true, 'ingestion_type' => 'api']);
        $this->assertDatabaseHas('sources', ['slug' => 'latribuna', 'enabled' => true]);
        $this->assertDatabaseHas('sources', ['slug' => 'dmh-automatic-stations', 'enabled' => true]);
        $this->assertDatabaseHas('sources', ['slug' => 'dmh-cap', 'enabled' => true]);
        $additionalPress = Source::query()->whereIn('slug', [
            'asuncion-actual', 'ahora-paraguay', 'elnacional', 'la-clave', 'ratio-paraguay',
        ])->get();
        $this->assertCount(5, $additionalPress);
        $this->assertTrue($additionalPress->every(fn (Source $source): bool => $source->enabled
            && $source->polling_minutes === 15
            && ($source->configuration_json['location_search_article_body'] ?? null) === false));
        $this->assertSame(0, Incident::query()->count());
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
        $this->assertSame(5, Source::query()->count());
    }
}
