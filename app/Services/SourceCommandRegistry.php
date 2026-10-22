<?php

namespace App\Services;

use App\Collectors;
use App\Discovery;
use App\Models\Source;

/** The commands and scheduler share this catalog so new supported sources cannot be omitted. */
class SourceCommandRegistry
{
    /** @return array<string, class-string> */
    public function scrapers(): array
    {
        $collectors = [
            'ande' => Collectors\AndeCollector::class,
            'essap' => Collectors\EssapCollector::class,
            'meteorologia' => Collectors\MeteorologyAdvisoryCollector::class,
            'siaguapy' => Collectors\SiaguapyHydrologyCollector::class,
            'migraciones' => Collectors\MigracionesCollector::class,
            'dinac' => Collectors\DinacCollector::class,
            'mspbs-health' => Collectors\MspbsCollector::class,
            'dgvs' => Collectors\DgvsCollector::class,
            'erssan' => Collectors\ErssanCollector::class,
            'senacsa' => Collectors\SenacsaCollector::class,
            'nasa-firms' => Collectors\NasaFirmsCollector::class,
            'abc' => Collectors\AbcColorCollector::class,
            'senepa' => Collectors\SenepaCollector::class,
            'ministerio-publico' => Collectors\MinisterioPublicoCollector::class,
            'senad' => Collectors\SenadCollector::class,
        ];

        foreach (['ultimahora', 'lanacion', 'infona', 'mades', 'mopc', 'sen', 'npy', 'telefuturo', 'unicanal', 'c9n', 'monumental', 'snt', 'hoy', 'popular', 'extra'] as $slug) {
            $collectors[$slug] = Collectors\PressHomepageCollector::class;
        }

        foreach (Source::query()->get(['slug', 'configuration_json']) as $source) {
            $class = match ($source->configuration_json['collector'] ?? null) {
                'press_homepage' => Collectors\PressHomepageCollector::class,
                'regional_press' => Collectors\RegionalPressCollector::class,
                'municipal' => Collectors\MunicipalNewsCollector::class,
                'utility_news' => Collectors\UtilityNewsCollector::class,
                default => null,
            };
            if ($class !== null) {
                $collectors[$source->slug] = $class;
            }
        }

        return $collectors;
    }

    /** @return array<string, class-string> */
    public function discovery(): array
    {
        return [
            'gdelt' => Discovery\GdeltDiscoveryProvider::class,
            'x-discovery' => Discovery\XDiscoveryProvider::class,
            'bluesky' => Discovery\BlueskyDiscoveryProvider::class,
            'mastodon' => Discovery\MastodonDiscoveryProvider::class,
            'telegram' => Discovery\TelegramChannelDiscoveryProvider::class,
            'youtube' => Discovery\YouTubeDiscoveryProvider::class,
        ];
    }

    /** @return array<string, class-string> */
    public function monitoring(): array
    {
        return [
            'mades-air-quality' => Collectors\MadesAirQualityCollector::class,
            'dmh-automatic-stations' => Collectors\DmhAutomaticStationCollector::class,
            'dmh-cap' => Collectors\DmhCapAlertCollector::class,
        ];
    }

    /** @return array<string, string> */
    public function commands(): array
    {
        return [
            ...array_fill_keys(array_keys($this->scrapers()), 'alertapy:scrape'),
            ...array_fill_keys(array_keys($this->discovery()), 'alertapy:discover'),
            ...array_fill_keys(array_keys($this->monitoring()), 'alertapy:sync-monitoring'),
        ];
    }

    public static function lockSeconds(): int
    {
        // max_seconds is a cooperative budget. Allow the last collector to finish
        // its own bounded HTTP requests before another cron obtains the lease.
        return max(3600, (int) config('alertapy.cycle.max_seconds', 180) + 600);
    }
}
