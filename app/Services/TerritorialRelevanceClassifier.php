<?php

namespace App\Services;

use Illuminate\Support\Str;

class TerritorialRelevanceClassifier
{
    /** @return array{decision: string, rule_id: string, reason: string, matched_tokens: array<int, string>} */
    public function classify(string $text): array
    {
        $plain = Str::lower(Str::ascii($text));
        $operational = ['cierre','corte','interrupcion','accidente','incendio','inundacion','bloqueo','desvio','alerta','emergencia','averia','restablecido','habilitado','detencion','allanamiento','operativo'];
        $institutional = ['acto institucional','saludo protocolar','aniversario institucional','firma de convenio','reunion de coordinacion','reunion protocolar','capacitacion interna','mensaje conmemorativo'];
        $positive = array_values(array_filter($operational, fn (string $token): bool => str_contains($plain, $token)));
        $negative = array_values(array_filter($institutional, fn (string $token): bool => str_contains($plain, $token)));
        if ($negative !== [] && $positive === []) return ['decision'=>'REJECT','rule_id'=>'NON_TERRITORIAL_INSTITUTIONAL','reason'=>'Contenido institucional sin afectación territorial operativa.','matched_tokens'=>$negative];
        if ($positive !== []) return ['decision'=>'PUBLISH','rule_id'=>'TERRITORIAL_OPERATIONAL_SIGNAL','reason'=>'Contiene una afectación territorial operativa reconocible.','matched_tokens'=>$positive];
        return ['decision'=>'WAIT','rule_id'=>'TERRITORIAL_RELEVANCE_UNCERTAIN','reason'=>'No hay una señal operativa suficiente para publicación automática.','matched_tokens'=>[]];
    }
}
