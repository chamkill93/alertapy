<?php

namespace Database\Seeders;

use App\Enums\GeorefMode;
use App\Enums\IngestionType;
use App\Enums\SourceType;
use App\Enums\TrustLevel;
use App\Models\Source;
use Illuminate\Database\Seeder;

/** Adds supplemental press sources without overwriting local changes. */
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
            [
                'name' => 'Ahendu',
                'slug' => 'ahendu',
                'domain' => 'ahendu.com.py',
                'polling_minutes' => 30,
                'listing_urls' => ['https://ahendu.com.py/', 'https://ahendu.com.py/ciudad/', 'https://ahendu.com.py/sociedad/'],
                'prefilter_requires_location' => false,
                'location_search_article_body' => true,
                'max_links' => 18,
                'max_candidates' => 12,
            ],
            [
                'name' => 'La Verdad Paraguaya',
                'slug' => 'la-verdad-paraguaya',
                'domain' => 'laverdadparaguaya.com',
                'polling_minutes' => 30,
                'prefilter_requires_location' => false,
                'location_search_article_body' => true,
                'max_links' => 18,
                'max_candidates' => 12,
            ],
            [
                'name' => 'El Independiente',
                'slug' => 'el-independiente',
                'domain' => 'independiente.com.py',
                'polling_minutes' => 30,
                'prefilter_requires_location' => false,
                'location_search_article_body' => true,
                'max_links' => 18,
                'max_candidates' => 12,
            ],
            [
                'name' => 'Parámetro',
                'slug' => 'parametro',
                'domain' => 'elparametro.com',
                'polling_minutes' => 30,
                'prefilter_requires_location' => false,
                'location_search_article_body' => true,
                'max_links' => 18,
                'max_candidates' => 12,
            ],
            [
                'name' => 'Pulso Paraguayo',
                'slug' => 'pulso-paraguayo',
                'domain' => 'pulsoparaguayo.com',
                'polling_minutes' => 30,
                'prefilter_requires_location' => false,
                'location_search_article_body' => true,
                'max_links' => 18,
                'max_candidates' => 12,
            ],
            [
                'name' => 'RDN - Resumen de Noticias',
                'slug' => 'rdn',
                'domain' => 'rdn.com.py',
                'polling_minutes' => 30,
                'listing_urls' => ['https://www.rdn.com.py/'],
                'prefilter_requires_location' => false,
                'location_search_article_body' => true,
                'max_links' => 30,
                'max_candidates' => 20,
            ],
            [
                'name' => 'Diario Paraguayo',
                'slug' => 'diario-paraguayo',
                'domain' => 'diarioparaguayo.com',
                'base_url' => 'https://www.diarioparaguayo.com.py/',
                'listing_urls' => ['https://www.diarioparaguayo.com.py/'],
                'polling_minutes' => 30,
                'prefilter_requires_location' => false,
                'location_search_article_body' => true,
                'max_links' => 30,
                'max_candidates' => 20,
            ],
        ];

        return array_map(function (array $publisher): array {
            $baseUrl = 'https://'.$publisher['domain'].'/';

            return [
                'name' => $publisher['name'],
                'slug' => $publisher['slug'],
                'domain' => $publisher['domain'],
                'type' => SourceType::Press,
                'ingestion_type' => IngestionType::Html,
                'georef_mode' => GeorefMode::ExtractAndGeocode,
                'trust_level' => TrustLevel::Medium,
                'base_url' => $publisher['base_url'] ?? $baseUrl,
                'polling_minutes' => $publisher['polling_minutes'] ?? 15,
                'enabled' => true,
                'configuration_json' => [
                    'collector' => 'press_homepage',
                    'listing_urls' => $publisher['listing_urls'] ?? [$baseUrl],
                    'prefilter_requires_location' => $publisher['prefilter_requires_location'] ?? true,
                    'max_age_hours' => 168,
                    'max_links' => $publisher['max_links'] ?? 8,
                    'max_candidates' => $publisher['max_candidates'] ?? 8,
                    'location_search_article_body' => $publisher['location_search_article_body'] ?? false,
                    'summary_title_only' => true,
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

