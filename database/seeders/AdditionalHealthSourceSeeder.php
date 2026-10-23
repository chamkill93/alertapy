<?php

namespace Database\Seeders;

use App\Enums\GeorefMode;
use App\Enums\IngestionType;
use App\Enums\SourceType;
use App\Enums\TrustLevel;
use App\Models\Source;
use Illuminate\Database\Seeder;

/** Adds official health channels that publish localized notices and reports. */
class AdditionalHealthSourceSeeder extends Seeder
{
    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        $sources = [
            [
                'name' => 'Dirección Nacional de Vigilancia Sanitaria',
                'slug' => 'dinavisa',
                'domain' => 'dinavisa.gov.py',
                'base_url' => 'https://dinavisa.gov.py/',
                'listing_urls' => [
                    'https://dinavisa.gov.py/noticias/',
                    'https://dinavisa.gov.py/category/alerta-sanitaria-2026/',
                ],
                'polling_minutes' => 30,
                'max_links' => 45,
                'max_candidates' => 18,
            ],
            [
                'name' => 'Instituto de Previsión Social',
                'slug' => 'ips',
                'domain' => 'portal.ips.gov.py',
                'base_url' => 'https://portal.ips.gov.py/sistemas/ipsportal/',
                'listing_urls' => [
                    'https://portal.ips.gov.py/sistemas/ipsportal/noticias.php',
                    'https://portal.ips.gov.py/sistemas/ipsportal/noticias.php?p=2',
                    'https://portal.ips.gov.py/sistemas/ipsportal/noticias.php?p=3',
                ],
                'polling_minutes' => 30,
                'max_links' => 60,
                'max_candidates' => 24,
            ],
            [
                'name' => 'Dirección General de Salud Ambiental',
                'slug' => 'digesa',
                'domain' => 'digesa.mspbs.gov.py',
                'base_url' => 'https://digesa.mspbs.gov.py/',
                'listing_urls' => ['https://digesa.mspbs.gov.py/category/noticias/'],
                'polling_minutes' => 60,
                'max_links' => 30,
                'max_candidates' => 12,
            ],
            [
                'name' => 'Instituto Nacional de Salud',
                'slug' => 'ins',
                'domain' => 'ins.gov.py',
                'base_url' => 'https://ins.gov.py/',
                'listing_urls' => ['https://ins.gov.py/'],
                'polling_minutes' => 60,
                'max_links' => 30,
                'max_candidates' => 12,
            ],
            [
                'name' => 'Instituto Nacional de Alimentación y Nutrición',
                'slug' => 'inan',
                'domain' => 'inan.gov.py',
                'base_url' => 'https://www.inan.gov.py/site/',
                'listing_urls' => ['https://www.inan.gov.py/site/'],
                'polling_minutes' => 60,
                'max_links' => 30,
                'max_candidates' => 12,
            ],
            [
                'name' => 'Dirección Nacional Estratégica de Recursos Humanos en Salud',
                'slug' => 'dnerhs',
                'domain' => 'dnerhs.mspbs.gov.py',
                'base_url' => 'https://dnerhs.mspbs.gov.py/',
                'listing_urls' => [
                    'https://dnerhs.mspbs.gov.py/index.php/category/dnerhs/noticias/',
                    'https://dnerhs.mspbs.gov.py/index.php/category/dnerhs/dn/dgprhs/eqips/',
                ],
                'polling_minutes' => 60,
                'max_links' => 30,
                'max_candidates' => 12,
            ],
        ];

        return array_map(static fn (array $definition): array => [
            'name' => $definition['name'],
            'slug' => $definition['slug'],
            'domain' => $definition['domain'],
            'base_url' => $definition['base_url'],
            'type' => SourceType::Official,
            'ingestion_type' => IngestionType::Html,
            'georef_mode' => GeorefMode::ExtractAndGeocode,
            'trust_level' => TrustLevel::High,
            'polling_minutes' => $definition['polling_minutes'],
            'enabled' => true,
            'configuration_json' => [
                'collector' => 'health_news',
                'listing_urls' => $definition['listing_urls'],
                'prefilter_requires_location' => false,
                'summary_title_only' => true,
                'max_age_hours' => 168,
                'max_links' => $definition['max_links'],
                'max_candidates' => $definition['max_candidates'],
                'location_search_article_body' => true,
                'category_classifier' => 'health',
            ],
        ], $sources);
    }

    public function run(): void
    {
        foreach (self::definitions() as $source) {
            // Keep any operator edits or disablement after the first seed.
            Source::query()->firstOrCreate(['slug' => $source['slug']], $source);
        }
    }
}
