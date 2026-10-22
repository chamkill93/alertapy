<?php

namespace App\Services;

use Illuminate\Support\Str;

class PressIncidentClassifier
{
    public function __construct(private readonly ?ClosingRuleEngine $closing = null) {}

    /** @var array<string, string> */
    private const CATEGORY_PATTERNS = [
        'electricity' => '/\b(cortes? (?:programados? )?(?:de|del) (?:energia|luz|servicio electrico|suministro electrico)|apagon(?:es)? (?:en|afecta|deja|golpea|provoca)|interrupcion(?:es)? (?:programadas? )?(?:de|del) (?:servicio electrico|suministro electrico|energia)|(?:linea|alimentador|subestacion)\w*.{0,55}fuera de servicio|(?:dej(?:a|an|o|aron)|qued(?:a|an|o|aron|ara|aran)) sin (?:luz|energia electrica|electricidad)|sin (?:energia electrica|electricidad)|barrios? sin luz)\b/u',
        'water' => '/\b((?:cortes?|interrupcion(?:es)?|suspension(?:es)?) (?:programad[oa]s? )?(?:de|del) (?:agua(?: potable)?|servicio de agua|suministro de agua)|falta de agua|sin agua|baja presion (?:de|del) agua|rotura (?:de|en) (?:una? )?(?:tuberia|caneria|acueducto)|(?:obras?|trabajos?|reparacion|mantenimiento).{0,100}(?:provision de agua|servicio de agua|suministro de agua|red de agua|tuberias?|canerias?))\b/u',
        'traffic_accident' => '/\b(accidente|choque|colision|vuelco|atropell|embestid|siniestro vial)\w*/u',
        'fire' => '/\b(incendio|incendia|fuego fuera de control)\w*/u',
        'weather_flood' => '/\b(inundacion|inundad|tormenta|temporal|granizo|desborde)\w*/u',
        'roads' => '/\b(bloqueo|cierres? de (?:calles?|rutas?)|cierre vial|desvios? (?:vehiculares?|de transito|por obras?)|protesta|manifest|manifies|congestion|bacheo|intervencion vial|ruta cerrada|camino\w* intransitable|(?:obra|rehabilitacion|mantenimiento|pavimentacion|reparacion|desague)\w*.{0,55}(?:ruta|avenida|camino|puente|calle|barrio))\w*/u',
        'security' => '/\b(asalto|robo|robamotos|balacera|operativo(?: policial| antidrogas)?|allanamiento|incaut|decomis|procedimiento antidrogas|homicid|asesin|feminic|secuestro|captur|detienen|detenido|imputan|imputado|acusacion|procesan)\w*/u',
        'health_vector' => '/\b(hantavirus|dengue|chikungunya|zika|brote epidemiologico|minga ambiental|control vectorial|eliminacion de criaderos|fumigacion)\b/u',
        'emergency' => '/\b(derrumbe|arbol caido|colapso estructural|evacuacion)\w*/u',
        'territorial_news' => '/\b(deforestacion|tala ilegal|contaminacion|fiscalizacion ambiental|intervencion ambiental|incumplimiento ambiental)\w*/u',
    ];

    public function classify(string $title, string $content): ?string
    {
        // La mención en el cuerpo no basta: puede ser contexto histórico o lateral.
        // Exigirla en el título reduce publicaciones territoriales falsas.
        $evidence = Str::lower(Str::ascii($title));

        // Las expresiones figuradas no describen accidentes de tránsito.
        // Quitar solo la frase permite conservar otros hechos reales del título.
        $evidence = preg_replace('/\b(?:accidentes? (?:(?:dentro )?del destino|politicos?|historicos?)|choques? (?:de (?:poderes|opiniones|intereses)|politicos?))\b/u', '', $evidence) ?? $evidence;

        // Remove only the denied outage, retaining any different event reported
        // in the same headline (for example no water cut, but a power cut).
        $evidence = preg_replace('/\b(?:no (?:habra|hay|hubo|se (?:registran?|anuncian?|reportan?|preven?))|sin) (?:cortes?|interrupcion(?:es)?|suspension(?:es)?) (?:programad[oa]s? )?(?:de|del) (?:agua(?: potable)?|energia(?: electrica)?|luz|electricidad|(?:servicio|suministro) (?:electrico|de agua(?: potable)?))\b/u', '', $evidence) ?? $evidence;
        $evidence = preg_replace('/\bno (?:estan?|hay|qued(?:a|an|o|aron|ara|aran)|se quedan?) (?:\w+\s+){0,3}sin (?:luz|energia electrica|electricidad)\b/u', '', $evidence) ?? $evidence;

        // "Sin agua" can describe dehydration or a figure of speech. Require
        // a public-service context before treating that short phrase as an outage.
        if (preg_match('/\b(?:forense|autopsia|cadaver|ser humano|deshidratacion|ayuno)\b/u', $evidence) === 1
            || preg_match('/\b(?:essap|servicio|suministro|abastecimiento|agua potable|vecinos?|pobladores?|usuarios?|barrios?|familias?|viviendas?|hogares?|comunidades?)\b|\b(?:queda(?:n|ra|ran|ron)?|siguen?) sin agua\b/u', $evidence) !== 1) {
            $evidence = preg_replace('/\bsin agua\b/u', '', $evidence) ?? $evidence;
        }

        $restorationMention = preg_match('/\b(?:restablec(?:id[oa]s?|imiento|io|ieron|en|e)|normaliz(?:ad[oa]s?|acion|o|aron|an|a))\b/u', $evidence) === 1;
        // Share the closure engine's assertion rules: a plan or an unconfirmed
        // nominal restoration must not become a newly active service incident.
        $restoration = $restorationMention && ($this->closing ?? new ClosingRuleEngine)->containsClosingLanguage($evidence);

        if (preg_match('/\b(?:rio|arroyo)\b/u', $evidence) === 1
            && preg_match('/\b(?:crecida|desborde|bajante|sube)\b/u', $evidence) === 1) {
            if (preg_match('/\b(?:no|sin)\b.{0,45}\b(?:afecta\w*|inunda\w*|anega\w*|aisla\w*|danos?|riesgo|evacu\w*)\b/u', $evidence) === 1) {
                return null;
            }

            if (preg_match('/\b(?:arrasa|afecta|inunda|anega|aisla|evacuan|evacuacion|cierran? compuertas|cierre de compuertas)\b/u', $evidence) === 1) {
                return 'hydrology';
            }
        }

        foreach (self::CATEGORY_PATTERNS as $category => $pattern) {
            $restoredService = $restoration && match ($category) {
                'electricity' => preg_match('/\b(?:energia electrica|electricidad|(?:servicio|suministro) (?:electrico|de energia)|luz)\b/u', $evidence) === 1,
                'water' => preg_match('/\b(?:agua potable|(?:servicio|suministro|abastecimiento|provision) (?:de )?agua)\b/u', $evidence) === 1,
                default => false,
            };
            $categoryEvidence = $category === 'water' && $restorationMention && ! $restoration
                ? (preg_replace('/\b(?:obras?|trabajos?|reparacion|mantenimiento)\b/u', '', $evidence) ?? $evidence)
                : $evidence;
            if ($restoredService || preg_match($pattern, $categoryEvidence) === 1) {
                return $category;
            }
        }

        return null;
    }
}
