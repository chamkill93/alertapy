<?php

namespace App\Services;

use App\DTOs\RoadLocationDTO;
use Illuminate\Support\Str;

class ParaguayRoadLocationParser
{
    public function __construct(private readonly RoadNameNormalizer $normalizer) {}

    public function parse(string $text): ?RoadLocationDTO
    {
        $plain = Str::ascii($text);
        $route = null;
        if (preg_match('/\b(?:ruta(?:\s+nacional)?\s*)?(PY\s*[- ]?\s*0?\d{1,2})\b/iu', $plain, $match) === 1) $route = $this->normalizer->normalize($match[1]);
        elseif (preg_match('/\bruta\s+([0-9]{1,2}|I{1,3}|IV|VI{0,3}|IX|X)\b/iu', $plain, $match) === 1) {
            $roman = ['I'=>1,'II'=>2,'III'=>3,'IV'=>4,'V'=>5,'VI'=>6,'VII'=>7,'VIII'=>8,'IX'=>9,'X'=>10];
            $number = is_numeric($match[1]) ? (int) $match[1] : ($roman[Str::upper($match[1])] ?? 0);
            if ($number > 0) $route = 'PY'.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
        }
        if ($route === null) return null;

        $kilometer = null;
        if (preg_match('/\b(?:km|kil[oó]metros?)\.?\s*(\d{1,4}(?:[\.,]\d{1,3})?)/iu', $plain, $km) === 1) $kilometer = (float) str_replace(',', '.', $km[1]);
        elseif (preg_match('/\b(\d{1,4}(?:[\.,]\d+)?)\s*(?:km|kil[oó]metros?)\b/iu', $plain, $km) === 1) $kilometer = (float) str_replace(',', '.', $km[1]);

        $relativeOffset = null; $directionUnknown = false;
        if (preg_match('/\b(\d{1,4})\s*(?:m|metros?)\s+(antes|despu[eé]s)\s+del?\s+(?:km|kil[oó]metro)/iu', $plain, $offset) === 1) {
            $relativeOffset = (int) $offset[1] * (Str::lower(Str::ascii($offset[2])) === 'antes' ? -1 : 1);
            $directionUnknown = preg_match('/\b(?:sentido|direcci[oó]n)\s+[\pL ]+/iu', $text) !== 1;
        }
        $intersection = null;
        if (preg_match('/\b(?:cruce\s+de\s+la\s+)?(?:ruta\s+)?PY\s*[- ]?\s*0?\d{1,2}\s+(?:y|con)\s+([^,.;]+)/iu', $plain, $cross) === 1) $intersection = trim($cross[1]);

        $locality = null;
        if (preg_match('/\b(?:en|jurisdicci[oó]n\s+de|acceso\s+a)\s+([\pL][\pL\s\'\-]{2,60}?)(?=\s*(?:,|\.|;|$))/iu', $text, $place) === 1) $locality = trim($place[1]);
        if ($locality === null && $intersection !== null && preg_match('/\bacceso\s+a\s+([\pL][\pL\s\'\-]{2,50})/iu', $intersection, $access) === 1) $locality = trim($access[1]);

        return new RoadLocationDTO(originalText: $text, roadCode: $route, kilometer: $kilometer, relativeOffsetM: $relativeOffset, city: $locality, intersectionText: $intersection, directionUnknown: $directionUnknown, evidence: ['route_text' => $match[0] ?? $route, 'kilometer_text' => $km[0] ?? null, 'intersection' => $intersection, 'location_name' => $locality]);
    }
}
