<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class MapReferenceLayerBuildTest extends TestCase
{
    public function test_it_builds_grouped_local_road_and_river_layers(): void
    {
        $directory = storage_path('framework/testing/map-layers-'.Str::uuid());
        File::ensureDirectoryExists($directory);

        try {
            $roadsPath = $directory.DIRECTORY_SEPARATOR.'roads.geojson';
            $riversPath = $directory.DIRECTORY_SEPARATOR.'rivers-source.geojson';
            $outputPath = $directory.DIRECTORY_SEPARATOR.'output';

            File::put($roadsPath, json_encode($this->collection([
                $this->line('PY02', 'Ruta Nacional', 'Asfalto', [[-57.6, -25.3], [-57.5, -25.3]]),
                $this->line('PY02 - Acceso', 'Ruta Nacional', 'Asfalto', [[-57.5, -25.3], [-57.4, -25.3]]),
                $this->line('D044', 'Ruta Departamental', 'Tierra', [[-57.1, -25.1], [-57.0, -25.0]]),
            ]), JSON_THROW_ON_ERROR));
            File::put($riversPath, json_encode($this->collection([
                $this->river('Rio Paraguay', [[-58.0, -24.0], [-57.9, -24.1]]),
                $this->river('RIO PARAGUAY', [[-57.9, -24.1], [-57.8, -24.2]]),
                $this->river('Arroyo Guazu', [[-57.7, -24.3], [-57.6, -24.4]]),
            ]), JSON_THROW_ON_ERROR));

            $this->artisan('alertapy:map-layers:build', [
                '--roads' => $roadsPath,
                '--rivers' => $riversPath,
                '--output' => $outputPath,
            ])->assertSuccessful();

            $roads = json_decode(File::get($outputPath.DIRECTORY_SEPARATOR.'roads.geojson'), true, 512, JSON_THROW_ON_ERROR);
            $rivers = json_decode(File::get($outputPath.DIRECTORY_SEPARATOR.'rivers.geojson'), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(3, $roads['metadata']['source_feature_count']);
            $this->assertCount(2, $roads['features']);
            $this->assertSame(
                ['departamental', 'nacional'],
                collect($roads['features'])->pluck('properties.classification')->sort()->values()->all(),
            );
            $this->assertSame(3, $rivers['metadata']['source_feature_count']);
            $this->assertCount(2, $rivers['features']);
            $this->assertSame(['river', 'stream'], collect($rivers['features'])->pluck('properties.classification')->sort()->values()->all());
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /** @param array<int, array<string, mixed>> $features */
    private function collection(array $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /** @param array<int, array{0: float, 1: float}> $coordinates */
    private function line(string $name, string $classification, string $surface, array $coordinates): array
    {
        return [
            'type' => 'Feature',
            'properties' => ['NOMBRE' => $name, 'DesTipoVia' => $classification, 'DesRodaVia' => $surface],
            'geometry' => ['type' => 'MultiLineString', 'coordinates' => [$coordinates]],
        ];
    }

    /** @param array<int, array{0: float, 1: float}> $coordinates */
    private function river(string $name, array $coordinates): array
    {
        return [
            'type' => 'Feature',
            'properties' => ['NOMBRE' => $name],
            'geometry' => ['type' => 'MultiLineString', 'coordinates' => [$coordinates]],
        ];
    }
}
