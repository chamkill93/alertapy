<?php

namespace App\Discovery\Concerns;

use Illuminate\Support\Str;

trait FiltersTerritorialDiscovery
{
    private function isRelevant(string $text): bool
    {
        $normalized = Str::lower(Str::ascii(strip_tags($text)));

        return preg_match('/\b(sin luz|corte de (?:luz|energia|agua)|sin agua|accidente|choque|asalto|robo|incendio|inundacion|temporal|calle bloqueada|ruta bloqueada|derrumbe|evacuacion|emergencia)\w*/u', $normalized) === 1;
    }

    private function validPublicUrl(string $url): bool
    {
        $parts = parse_url($url);

        return in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && is_string($parts['host'] ?? null)
            && filter_var($parts['host'], FILTER_VALIDATE_IP) === false
            && ! in_array(strtolower($parts['host']), ['localhost', '127.0.0.1'], true);
    }
}
