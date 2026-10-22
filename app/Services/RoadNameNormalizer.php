<?php

namespace App\Services;

use Illuminate\Support\Str;

class RoadNameNormalizer
{
    public function normalize(string $value): string
    {
        $text = Str::upper(Str::ascii(trim($value)));
        $text = preg_replace('/\bRUTA(?:\s+NACIONAL)?\b/', ' ', $text) ?: $text;
        $text = preg_replace('/[^A-Z0-9]+/', '', $text) ?: $text;
        if (preg_match('/^(?:PY)?0*(\d{1,2})$/', $text, $match) === 1) return 'PY'.str_pad($match[1], 2, '0', STR_PAD_LEFT);
        return $text;
    }
}
