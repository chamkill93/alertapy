<?php

namespace App\Services;

use Illuminate\Support\Str;

/** Maps localized health notices to the public-health or vector-control layer. */
class HealthCategoryClassifier
{
    private const VECTOR_PATTERN = '/\b(dengue|chikungunya|zika|arbovirosis|aedes aegypti|mosquitos?|control vectorial|eliminacion de criaderos|criaderos de mosquitos?|minga ambiental|vigilancia entomologica|chagas|malaria|paludismo|leishmaniasis)\b/u';

    private const PUBLIC_HEALTH_PATTERN = '/\b(alertas? epidemiologicas?|alertas? sanitarias?|emergencias? sanitarias?|brotes?(?: epidemiologicos?)?|amenazas? sanitarias?|vigilancia (?:epidemiologica|comunitaria)|casos? (?:confirmados?|detectados?|positivos?)|circulacion comunitaria|virus respiratorios?|cuadros respiratorios?|rhinovirus|rinovirus|metapneumovirus|adenovirus|parainfluenza|virus sincitial(?: respiratorio)?|\bvrs\b|\brsv\b|sarampion|influenza|vacunacion|inmunizacion|hospital(?:es)?|servicios? de salud|servicios? medicos?|trasplantes?|donacion(?:es)? de organos|hantavirus|covid(?:-19)?|leptospirosis|tuberculosis|meningitis|neumonias?|rabia|hepatitis|fiebre amarilla|viruela simica|mpox|region sanitaria|unidades? de salud|\busf\b|atenciones? (?:medicas?|especializadas?)?|asistencia (?:medica|en salud)|atencion (?:medica|sanitaria)|jornada de atencion|consultas? medicas?|medicamentos(?: e insumos)?|insumos y medicamentos|terapia intensiva|ambulancias?|equipos? de salud|(?:bioquimic\w*|medic[oa]s?|enfermer[oa]s?|personal de salud).{0,60}(?:huelga|paro)|(?:huelga|paro).{0,60}(?:bioquimic\w*|medic[oa]s?|enfermer[oa]s?|personal de salud)|personal de salud|brigadas? de salud|profesionales? de salud|salud publica|octubre rosa|cancer(?:es)?|oncolog\w*|tumores?|salud mental|salud ocular|salud visual|salud bucodental|salud sexual|salud reproductiva|lactancia(?: materna)?|embarazo|maternidad|neonat\w*|obstetric\w*|ginecolog\w*|nutricion|vacunas?|diabetes|hipertension|obesidad|cardiovascular)\b/u';

    private const EPIDEMIC_CONTEXT_PATTERN = '/\b(alerta|brote|casos?|vigilancia|circulacion|epidemiolog|sanitari|reporta|sospechas?|aument|disminu|semanas?|regiones?)\w*/u';

    public function classify(string $title, string $context = ''): ?string
    {
        return $this->classifyAll($title, $context)[0] ?? null;
    }

    /** @return list<string> */
    public function classifyAll(string $title, string $context = ''): array
    {
        // Official health stories often put the event detail in the lead while
        // keeping the headline generic. Use this article-specific context; the
        // collector still requires a confirmed Paraguayan location for the map.
        $evidence = Str::lower(Str::ascii($title.' '.$context));
        $categories = [];

        if (preg_match(self::VECTOR_PATTERN, $evidence) === 1) {
            $categories[] = 'health_vector';
        }

        // Epidemiology reports about vector-borne diseases belong in both
        // health layers; operational SENEPA prevention stays vector-only.
        $vectorEpidemiology = preg_match(self::VECTOR_PATTERN, $evidence) === 1
            && preg_match(self::EPIDEMIC_CONTEXT_PATTERN, $evidence) === 1;

        if (preg_match(self::PUBLIC_HEALTH_PATTERN, $evidence) === 1 || $vectorEpidemiology) {
            $categories[] = 'health_public';
        }

        return array_values(array_unique($categories));
    }
}

