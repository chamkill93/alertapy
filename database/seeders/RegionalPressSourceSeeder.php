<?php

namespace Database\Seeders;

use App\Enums\GeorefMode;
use App\Enums\IngestionType;
use App\Enums\SourceType;
use App\Enums\TrustLevel;
use App\Models\Source;
use Illuminate\Database\Seeder;

/** Adds active regional news sites while preserving existing operator choices. */
class RegionalPressSourceSeeder extends Seeder
{
    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        $publishers = [
            [
                'name' => 'Caazapá Ahora',
                'slug' => 'caazapa-ahora',
                'domain' => 'caazapaahora.com.py',
                'regional_department' => 'Caazapá',
                'listing_urls' => ['https://caazapaahora.com.py/', 'https://caazapaahora.com.py/locales'],
            ],
            [
                'name' => 'Norte Digital',
                'slug' => 'norte-digital',
                'domain' => 'nortedigital.com.py',
                'regional_department' => 'San Pedro',
                'listing_urls' => ['https://www.nortedigital.com.py/inicio'],
            ],
            [
                'name' => 'Red Digital San Pedro',
                'slug' => 'red-digital-san-pedro',
                'domain' => 'reddigitalsanpedro.com.py',
                'regional_department' => 'San Pedro',
                'listing_urls' => ['https://www.reddigitalsanpedro.com.py/'],
            ],
            [
                'name' => 'Digital Misiones',
                'slug' => 'digital-misiones',
                'domain' => 'digitalmisiones.com.py',
                'regional_department' => 'Misiones',
                'listing_urls' => ['https://digitalmisiones.com.py/'],
            ],
            [
                'name' => 'QAP Chaco News',
                'slug' => 'qap-chaco-news',
                'domain' => 'qapchaconews.com',
                'regional_department' => null,
                'listing_urls' => ['https://qapchaconews.com/'],
            ],
            [
                'name' => 'Caaguazú Noticias Digital',
                'slug' => 'caaguazu-noticias-digital',
                'domain' => 'caaguazunoticiasdigital.com.py',
                'regional_department' => 'Caaguazú',
                'listing_urls' => ['https://caaguazunoticiasdigital.com.py/', 'https://caaguazunoticiasdigital.com.py/category/locales/'],
            ],
            [
                'name' => 'Diario Vanguardia',
                'slug' => 'diario-vanguardia',
                'domain' => 'diariovanguardia.com.py',
                'regional_department' => 'Alto Paraná',
                'listing_urls' => ['https://diariovanguardia.com.py/', 'https://diariovanguardia.com.py/category/locales/'],
            ],
            [
                'name' => 'Concepción al Día',
                'slug' => 'concepcion-al-dia',
                'domain' => 'concepcionaldia.com',
                'regional_department' => 'Concepción',
                'listing_urls' => ['https://www.concepcionaldia.com/'],
            ],
            [
                'name' => 'Tercer Ojo',
                'slug' => 'tercer-ojo',
                'domain' => 'tercerojo.com.py',
                'regional_department' => 'Cordillera',
                'listing_urls' => ['https://tercerojo.com.py/'],
            ],
            [
                'name' => 'Portal de Curuguaty',
                'slug' => 'portal-curuguaty',
                'domain' => 'investigacion365.com',
                'regional_department' => 'Canindeyú',
                'listing_urls' => [
                    'https://investigacion365.com/',
                    'https://investigacion365.com/category/locales/',
                    'https://investigacion365.com/category/sucesos/',
                ],
            ],
            [
                'name' => 'AGR 101.3 FM / Revista Unión',
                'slug' => 'agr-fm-tv',
                'domain' => 'agrfmtv.com.py',
                'regional_department' => 'Canindeyú',
                'listing_urls' => ['https://www.agrfmtv.com.py/'],
            ],
            [
                'name' => 'Canindeyú Digital',
                'slug' => 'canindeyu-digital',
                'domain' => 'canindeyudigital.com',
                'regional_department' => 'Canindeyú',
                'listing_urls' => ['https://www.canindeyudigital.com/canindeyu'],
            ],
            [
                'name' => 'Portal Capitán Meza',
                'slug' => 'portal-capitan-meza',
                'domain' => 'capitanmeza.com.py',
                'regional_department' => 'Itapúa',
                'listing_urls' => ['https://capitanmeza.com.py/'],
            ],
            [
                'name' => 'Radio Guairá',
                'slug' => 'radio-guaira',
                'domain' => 'radioguaira.com',
                'regional_department' => 'Guairá',
                'listing_urls' => ['https://www.radioguaira.com/'],
            ],
            [
                'name' => 'Pedro Juan Digital',
                'slug' => 'pedro-juan-digital',
                'domain' => 'pedrojuandigital.com.py',
                'regional_department' => 'Amambay',
                'listing_urls' => ['https://pedrojuandigital.com.py/', 'https://pedrojuandigital.com.py/category/locales/'],
            ],
            [
                'name' => 'Amambay Digital',
                'slug' => 'amambay-digital',
                'domain' => 'amambaydigital.com',
                'regional_department' => 'Amambay',
                'listing_urls' => ['https://amambaydigital.com/'],
            ],
            [
                'name' => 'Luque Noticias',
                'slug' => 'luque-noticias',
                'domain' => 'luquenoticias.com.py',
                'regional_department' => 'Central',
                'listing_urls' => ['https://www.luquenoticias.com.py/'],
            ],
            [
                'name' => 'Últimas Noticias',
                'slug' => 'ultimas-noticias-cde',
                'domain' => 'ultimasnoticias.com.py',
                'regional_department' => 'Alto Paraná',
                'listing_urls' => ['https://ultimasnoticias.com.py/'],
            ],
            [
                'name' => 'Hechos Digital',
                'slug' => 'hechos-digital',
                'domain' => 'hechosdigital.com.py',
                'regional_department' => 'Amambay',
                'listing_urls' => ['https://hechosdigital.com.py/'],
            ],
            [
                'name' => 'TVS Digital',
                'slug' => 'tvs-digital',
                'domain' => 'tvs.com.py',
                'regional_department' => 'Itapúa',
                'listing_urls' => ['https://tvs.com.py/', 'https://tvs.com.py/category/locales/'],
            ],
            [
                'name' => 'Radio ZP30',
                'slug' => 'radio-zp30',
                'domain' => 'zp30.com.py',
                'regional_department' => 'Boquerón',
                'listing_urls' => ['https://www.zp30.com.py/es/category/noticias/noticias-locales/'],
            ],
        ];

        return array_map(function (array $publisher): array {
            $baseUrl = 'https://'.$publisher['domain'].'/';

            return [
                'name' => $publisher['name'],
                'slug' => $publisher['slug'],
                'type' => SourceType::Press,
                'ingestion_type' => IngestionType::Html,
                'georef_mode' => GeorefMode::ExtractAndGeocode,
                'trust_level' => TrustLevel::Medium,
                'base_url' => $baseUrl,
                'domain' => $publisher['domain'],
                'polling_minutes' => 30,
                'enabled' => true,
                'configuration_json' => [
                    'collector' => $publisher['regional_department'] === null ? 'press_homepage' : 'regional_press',
                    'regional_department' => $publisher['regional_department'],
                    'listing_urls' => $publisher['listing_urls'],
                    'prefilter_requires_location' => false,
                    'summary_title_only' => true,
                    'max_age_hours' => 168,
                    'max_links' => 18,
                    'max_candidates' => 12,
                    'location_search_article_body' => true,
                ],
            ];
        }, $publishers);
    }

    public function run(): void
    {
        foreach (self::definitions() as $source) {
            // First-run defaults are opt-in. Existing settings and disablement are operator-owned.
            Source::query()->firstOrCreate(['slug' => $source['slug']], $source);
        }
    }
}

