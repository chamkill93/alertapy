<?php

namespace App\Discovery\Concerns;

use Illuminate\Support\Str;

trait FiltersTerritorialDiscovery
{
    private function isRelevant(string $text): bool
    {
        $normalized = Str::lower(Str::ascii(strip_tags($text)));

        return preg_match('/\b(sin luz|apag[oó]n|corte de (?:luz|energia|agua)|sin agua|agua potable|falta de agua|servicio electrico|accidente|choque|siniestro|asalto|homicidio|robo|abigeato|incendio|dengue|chikungunya|zika|malaria|sarampion|brote|epidemia|alerta sanitaria|fiebre aftosa|rabia|intoxicacion|hospital|crecida|desborde|inundacion|temporal|tormenta|granizada|sequ[ií]a|nivel del rio|humo|contaminacion|calidad del aire|derrame|calle bloqueada|ruta bloqueada|puente cortado|derrumbe|evacuacion|rescate|paso fronterizo|emergencia)\w*/u', $normalized) === 1;
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
