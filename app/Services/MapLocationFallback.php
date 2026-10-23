<?php

namespace App\Services;

use App\Enums\SourceType;
use Illuminate\Support\Str;

class MapLocationFallback
{
    public const METHOD = 'ASUNCION_REFERENCE_FALLBACK';

    public const LABEL = 'Ubicación no informada · referencia cartográfica en Asunción';

    public const ASUNCION_LATITUDE = -25.2800459;

    public const ASUNCION_LONGITUDE = -57.6343814;

    public function isClearlyEditorial(string ...$text): bool
    {
        return preg_match('/\b(?:columna de opinion|de opinion|opinion|editorial|reflexion|analisis de opinion|navegando en tormenta)\b/u', Str::lower(Str::ascii(implode(' ', $text)))) === 1;
    }

    /** @param array{text: string, query: string, city: ?string, department: ?string, accuracy: string}|null $location
     *  @return array{text: string, query: string, city: ?string, department: ?string, accuracy: string, is_fallback: bool, latitude?: float, longitude?: float}|null
     */
    public function forPress(SourceType $sourceType, ?array $location): ?array
    {
        if ($location !== null) {
            return [...$location, 'is_fallback' => false];
        }

        if ($sourceType !== SourceType::Press) {
            return null;
        }

        return [
            'text' => self::LABEL,
            'query' => 'Asunción',
            'city' => null,
            'department' => null,
            'accuracy' => 'approximate',
            'is_fallback' => true,
            'latitude' => self::ASUNCION_LATITUDE,
            'longitude' => self::ASUNCION_LONGITUDE,
        ];
    }
}
