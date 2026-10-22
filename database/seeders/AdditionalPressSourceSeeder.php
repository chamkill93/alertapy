<?php

namespace Database\Seeders;

use App\Enums\GeorefMode;
use App\Enums\IngestionType;
use App\Enums\SourceType;
use App\Enums\TrustLevel;
use App\Models\Source;
use Illuminate\Database\Seeder;

/** Adds five independently operated press sources without overwriting local changes. */
class AdditionalPressSourceSeeder extends Seeder
{
    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        $publishers = [
            ['name' => 'Asunción Actual', 'slug' => 'asuncion-actual', 'domain' => 'asuncionactual.com'],
            ['name' => 'Ahora Paraguay', 'slug' => 'ahora-paraguay', 'domain' => 'ahoraparaguay.com'],
            ['name' => 'El Nacional', 'slug' => 'elnacional', 'domain' => 'elnacional.com.py'],
            ['name' => 'Diario La Clave', 'slug' => 'la-clave', 'domain' => 'laclave.com.py'],
            ['name' => 'Ratio Paraguay', 'slug' => 'ratio-paraguay', 'domain' => 'ratioparaguay.com.py'],
        ];

        return array_map(function (array $publisher): array {
            $baseUrl = 'https://'.$publisher['domain'].'/';

            return [
                ...$publisher,
                'type' => SourceType::Press,
                'ingestion_type' => IngestionType::Html,
                'georef_mode' => GeorefMode::ExtractAndGeocode,
                'trust_level' => TrustLevel::Medium,
                'base_url' => $baseUrl,
                'polling_minutes' => 15,
                'enabled' => true,
                'configuration_json' => [
                    'collector' => 'press_homepage',
                    'listing_urls' => [$baseUrl],
                    'prefilter_requires_location' => true,
                    'max_age_hours' => 168,
                    'max_links' => 8,
                    'max_candidates' => 8,
                    'location_search_article_body' => false,
                ],
            ];
        }, $publishers);
    }

    public function run(): void
    {
        foreach (self::definitions() as $source) {
            // Existing per-source state and any operator disablement are kept intact.
            $existing = Source::query()->where('slug', $source['slug'])->first();

            if ($existing === null) {
                Source::query()->create($source);

                continue;
            }

            $configuration = (array) ($existing->configuration_json ?? []);
            if (! array_key_exists('location_search_article_body', $configuration)) {
                $configuration['location_search_article_body'] = false;
                $existing->forceFill(['configuration_json' => $configuration])->save();
            }
        }
    }
}
