<?php

namespace Database\Seeders;

use App\Enums\GeorefMode;
use App\Enums\IngestionType;
use App\Enums\SourceType;
use App\Enums\TrustLevel;
use App\Models\Source;
use Illuminate\Database\Seeder;

class UtilityNewsSourceSeeder extends Seeder
{
    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return array_map(fn (array $publisher) => [
            ...$publisher,
            'type' => SourceType::Press,
            'ingestion_type' => IngestionType::Api,
            'georef_mode' => GeorefMode::ExtractAndGeocode,
            'trust_level' => TrustLevel::Medium,
            'polling_minutes' => 15,
            'configuration_json' => ['collector' => 'utility_news'],
        ], [
            ['name' => '1000 Noticias', 'slug' => '1000noticias', 'base_url' => 'https://1000noticias.com.py/', 'domain' => '1000noticias.com.py'],
            ['name' => 'Ñandutí', 'slug' => 'nanduti', 'base_url' => 'https://nanduti.live/', 'domain' => 'nanduti.live'],
        ]);
    }

    public function run(): void
    {
        foreach (self::definitions() as $source) {
            // Safe upgrade: preserve any local settings/disablement on an existing source.
            Source::query()->firstOrCreate(['slug' => $source['slug']], [
                ...$source, 'enabled' => (bool) config("alertapy.sources.{$source['slug']}.enabled", true),
            ]);
        }
    }
}
