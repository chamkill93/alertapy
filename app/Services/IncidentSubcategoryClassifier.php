<?php

namespace App\Services;

use Illuminate\Support\Str;

class IncidentSubcategoryClassifier
{
    /** @var array<string, array<string, array<int, string>>> */
    private array $rules = [
        'security' => ['robo'=>['robo','robado'], 'asalto'=>['asalto','asalt'], 'hurto'=>['hurto'], 'allanamiento'=>['allanamiento'], 'detencion'=>['detenido','detencion','aprehendido'], 'operativo'=>['operativo'], 'desaparicion'=>['desaparecid'], 'violencia'=>['violencia','agresion']],
        'traffic_accident' => ['atropellamiento'=>['atropell'], 'vuelco'=>['vuelco','volcamiento'], 'congestion'=>['congestion','embotellamiento'], 'cierre'=>['cierre','bloqueado'], 'desvio'=>['desvio'], 'fallecimiento_vial'=>['fallecio','victima fatal'], 'vehiculo_incendiado'=>['vehiculo incendiado'], 'colision'=>['choque','colision','accidente']],
        'electricity' => ['restablecimiento'=>['restablecido','normalizado'], 'trabajo_programado'=>['trabajo programado','intervencion programada'], 'averia'=>['averia','falla'], 'mantenimiento'=>['mantenimiento'], 'corte'=>['corte','interrupcion']],
        'water' => ['baja_presion'=>['baja presion'], 'restablecimiento'=>['restablecido','normalizado'], 'averia'=>['averia','rotura','fuga'], 'mantenimiento'=>['mantenimiento'], 'calidad'=>['calidad del agua','no potable'], 'corte'=>['corte','interrupcion']],
        'weather_flood' => ['cap_alert'=>['alerta cap'], 'tormenta'=>['tormenta'], 'granizo'=>['granizo'], 'viento'=>['viento','rafaga'], 'temperatura'=>['temperatura','calor','frio'], 'lluvia'=>['lluvia','precipitacion'], 'pronostico'=>['pronostico']],
        'fire' => ['foco_calor'=>['foco de calor','deteccion termica'], 'vehicular'=>['vehiculo','automovil'], 'forestal'=>['forestal','bosque'], 'vegetacion'=>['pastizal','vegetacion'], 'controlado'=>['controlado'], 'extinguido'=>['extinguido'], 'estructural'=>['vivienda','edificio','deposito']],
        'roads' => ['cierre'=>['cierre','bloqueo'], 'desvio'=>['desvio'], 'habilitacion'=>['habilitado','reabierto'], 'puente'=>['puente'], 'derrumbe'=>['derrumbe','deslizamiento'], 'mantenimiento'=>['mantenimiento'], 'obra'=>['obra','construccion']],
        'health_vector' => ['minga_ambiental'=>['minga ambiental'], 'rastrillaje'=>['rastrillaje'], 'control_quimico'=>['control quimico','bloqueo quimico','fumigacion','rociado'], 'eliminacion_criaderos'=>['eliminacion de criaderos','destruccion de criaderos'], 'vigilancia_entomologica'=>['vigilancia entomologica','monitoreo entomologico'], 'educacion_preventiva'=>['concienciacion','educacion para la salud','prevencion de arbovirosis']],
    ];

    public function classify(string $category, string $text): ?string
    {
        $plain = Str::lower(Str::ascii($text));
        foreach ($this->rules[$category] ?? [] as $subcategory => $tokens) {
            if (collect($tokens)->contains(fn (string $token): bool => str_contains($plain, $token))) return $subcategory;
        }
        return null;
    }
}
