<?php

namespace App\Services;

use Illuminate\Support\Str;

/** Maps localized health headlines to the public-health or vector-control layer. */
class HealthCategoryClassifier
{
    private const VECTOR_PATTERN = '/\b(dengue|chikungunya|zika|arbovirosis|aedes aegypti|mosquitos?|control vectorial|eliminacion de criaderos|criaderos de mosquitos?|minga ambiental|vigilancia entomologica|chagas|malaria|paludismo|leishmaniasis)\b/u';

    private const PUBLIC_HEALTH_PATTERN = '/\b(alertas? epidemiologicas?|alertas? sanitarias?|emergencias? sanitarias?|brotes?(?: epidemiologicos?)?|amenazas? sanitarias?|vigilancia (?:epidemiologica|comunitaria)|casos? (?:confirmados?|detectados?|positivos?)|circulacion comunitaria|virus respiratorios?|cuadros respiratorios?|sarampion|influenza|vacunacion|inmunizacion|hospital(?:es)?|servicios? de salud|servicios? medicos?|trasplantes?|donacion(?:es)? de organos|hantavirus|covid(?:-19)?|leptospirosis|tuberculosis|meningitis|neumonias?|rabia|hepatitis|fiebre amarilla|viruela simica|mpox|region sanitaria|unidades? de salud|\busf\b|atenciones? (?:medicas?|especializadas?)?|asistencia (?:medica|en salud)|atencion (?:medica|sanitaria)|jornada de atencion|consultas? medicas?|medicamentos(?: e insumos)?|insumos y medicamentos|terapia intensiva|ambulancias?|equipos? de salud|personal de salud|brigadas? de salud|profesionales? de salud|salud publica)\b/u';

    private const EPIDEMIC_CONTEXT_PATTERN = '/\b(alerta|brote|casos?|vigilancia|circulacion|epidemiolog|sanitari|reporta|sospechas?|aument|disminu|semanas?|regiones?)\w*/u';

    public function classify(string $title): ?string
    {
        return $this->classifyAll($title)[0] ?? null;
    }

    /** @return list<string> */
    public function classifyAll(string $title): array
    {
        $headline = Str::lower(Str::ascii($title));
        $categories = [];

        if (preg_match(self::VECTOR_PATTERN, $headline) === 1) {
            $categories[] = 'health_vector';
        }

        // Epidemiology reports about vector-borne diseases belong in both
        // health layers; operational SENEPA prevention stays vector-only.
        $vectorEpidemiology = preg_match(self::VECTOR_PATTERN, $headline) === 1
            && preg_match(self::EPIDEMIC_CONTEXT_PATTERN, $headline) === 1;

        if (preg_match(self::PUBLIC_HEALTH_PATTERN, $headline) === 1 || $vectorEpidemiology) {
            $categories[] = 'health_public';
        }

        return array_values(array_unique($categories));
    }
}

