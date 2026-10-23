<?php

namespace App\Discovery\Concerns;

use Illuminate\Support\Str;

trait FiltersTerritorialDiscovery
{
    private function isRelevant(string $text): bool
    {
        $normalized = Str::lower(Str::ascii(strip_tags($text)));

        return preg_match('/\b(sin luz|sin energia|sin electricidad|apag[oó]n|cortes? de (?:luz|energia|electricidad|agua)|interrupciones? (?:de|del) (?:energia|electricidad|servicio electrico|agua)|sin agua|agua potable|falta de agua|servicio electrico|accidente|choque|colision|atropell|siniestro vial|asalto|homicidio|robo|abigeato|incendio|dengue|chikungunya|zika|arbovirosis|hantavirus|malaria|sarampion|influenza|leptospirosis|tuberculosis|meningitis|hepatitis|fiebre amarilla|mpox|covid|brote|epidemia|alerta sanitaria|fiebre aftosa|aftosa bovina|gripe aviar|brucelosis|rabia|peste porcina|newcastle|encefalomielitis equina|anemia infecciosa equina|sanidad animal|intoxicacion|hospital|crecida|desborde|inundacion|temporal|tormenta|granizada|sequ[ií]a|nivel del rio|humo|contaminacion|calidad del aire|derrame|calle bloqueada|ruta bloqueada|cierre de (?:ruta|calle|puente)|puente cortado|derrumbe|evacuacion|rescate|paso fronterizo|emergencia|suspension de clases|clases suspendidas|escuela cerrada|cierre de escuelas|school closure|school evacuation|protesta docente|teacher strike|hospital cerrado|hospital clausurado|sin ambulancia|sin medicamentos|comunidad aislada|comunidades aisladas|pobladores aislados|familias evacuadas|viviendas evacuadas|community isolated|evacuated families)\w*/u', $normalized) === 1;
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
