<?php

namespace Tests\Unit;

use App\Services\PressIncidentClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PressIncidentClassifierTest extends TestCase
{
    #[DataProvider('powerOutageTitles')]
    public function test_it_identifies_concrete_power_outages(string $title): void
    {
        $this->assertSame('electricity', (new PressIncidentClassifier)->classify($title, ''));
    }

    /** @return array<string, array{string}> */
    public static function powerOutageTitles(): array
    {
        return [
            'line out of service' => ['Línea de baja tensión fuera de servicio deja sin luz a varios barrios'],
            'scheduled interruption' => ['Interrupción programada del servicio eléctrico en San Lorenzo'],
            'power cut' => ['Corte de energía afecta a Luque'],
            'scheduled power cuts' => ['Cortes programados de energía afectarán a Asunción este martes'],
            'blackout' => ['Apagón en Asunción'],
            'scheduled loss' => ['Capiatá quedará sin energía eléctrica mañana por obras de ANDE'],
            'cerrito isolated families' => ['Aislados, bajo agua y sin energía eléctrica: 400 familias esperan ayuda humanitaria en Cerrito'],
            'explicit electricity loss' => ['Familias sin electricidad en Cerrito esperan ayuda'],
        ];
    }

    public function test_it_does_not_confuse_ande_business_news_with_an_outage(): void
    {
        $classifier = new PressIncidentClassifier;

        $this->assertNull($classifier->classify('Empresa realiza una oferta a la ANDE', ''));
        $this->assertNull($classifier->classify('Sin luz, agua ni caminos: así funciona una escuela rural', ''));
        $this->assertNull($classifier->classify('Investigan desvío de G. 300 millones en Ciudad del Este', ''));
    }

    #[DataProvider('waterIncidentTitles')]
    public function test_it_identifies_concrete_water_service_events(string $title): void
    {
        $this->assertSame('water', (new PressIncidentClassifier)->classify($title, ''));
    }

    /** @return array<string, array{string}> */
    public static function waterIncidentTitles(): array
    {
        return [
            'outage' => ['Corte de agua afecta a Luque'],
            'scheduled' => ['Interrupción programada del servicio de agua en Asunción'],
            'without service' => ['Vecinos de San Lorenzo siguen sin agua'],
            'pipe rupture' => ['Rotura de tubería principal deja a Asunción sin agua'],
            'works affect supply' => ['ESSAP anuncia obras y Asunción podría verse afectada en la provisión de agua'],
            'city without service' => ['San Bernardino queda sin agua potable por más de doce horas'],
        ];
    }

    public function test_water_institutional_news_and_background_mentions_are_not_incidents(): void
    {
        $classifier = new PressIncidentClassifier;

        $this->assertNull($classifier->classify('ESSAP fortalece su gestión institucional en Asunción', 'Hubo falta de agua en 2024.'));
        $this->assertNull($classifier->classify('ESSAP presenta una campaña sobre ahorro de agua', ''));
        $this->assertNull($classifier->classify('ESSAP anuncia obras en su edificio administrativo de Asunción', ''));
    }

    public function test_it_recognizes_territorial_road_security_and_health_titles(): void
    {
        $classifier = new PressIncidentClassifier;

        $this->assertSame('roads', $classifier->classify('Cierres de calles causan caos vehicular en Asunción', ''));
        $this->assertSame('roads', $classifier->classify('Pobladores se manifiestan en Ypacaraí', ''));
        $this->assertSame('security', $classifier->classify('Detienen a presuntos robamotos en Villa Elisa', ''));
        $this->assertSame('health_vector', $classifier->classify('Confirman casos de hantavirus en Boquerón', ''));
        $this->assertSame('health_vector', $classifier->classify('SENEPA realiza minga ambiental en Luque', ''));
        $this->assertSame('roads', $classifier->classify('Obra de rehabilitación de la Ruta PY08 avanza por tramos', ''));
        $this->assertSame('territorial_news', $classifier->classify('Fiscalización ambiental detecta tala ilegal en Caaguazú', ''));
    }

    public function test_air_quality_news_requires_an_explicit_adverse_condition(): void
    {
        $classifier = new PressIncidentClassifier;

        $this->assertSame('air_quality', $classifier->classify('Contaminación atmosférica supera límites sanitarios en Asunción', ''));
        $this->assertSame('air_quality', $classifier->classify('La mala calidad del aire afecta a grupos sensibles en Luque', ''));
        $this->assertSame('air_quality', $classifier->classify('Calidad del aire supera los límites en Asunción', ''));
        $this->assertNull($classifier->classify('MADES habilita una estación para monitorear la calidad del aire en Asunción', ''));
        $this->assertSame('territorial_news', $classifier->classify('Exigen retirar cartelería política por contaminación visual en Pdte. Franco', ''));
    }

    public function test_it_avoids_generic_budget_and_foreign_accusation_headlines_as_security_incidents(): void
    {
        $classifier = new PressIncidentClassifier;

        $this->assertNull($classifier->classify('El MEF achica su presupuesto global, pero eleva su gasto operativo', ''));
        $this->assertNull($classifier->classify('Cuba rechaza las falsas acusaciones de EEUU por la guerra en Ucrania', ''));
        $this->assertSame('security', $classifier->classify('Operativo policial con detenidos en Pedro Juan Caballero', ''));
    }

    public function test_it_returns_both_utility_categories_when_a_headline_reports_both_services(): void
    {
        $classifier = new PressIncidentClassifier;

        $this->assertSame(
            ['electricity', 'water'],
            $classifier->classifyAll('Cortes programados de energía y agua afectarán a Asunción este viernes', ''),
        );
        $this->assertSame(
            ['electricity'],
            $classifier->classifyAll('No habrá cortes de agua, pero habrá un corte de luz en Luque', ''),
        );
    }

    public function test_river_events_require_concrete_impact_and_ignore_normal_or_negated_reports(): void
    {
        $classifier = new PressIncidentClassifier;

        $this->assertSame('hydrology', $classifier->classify('Crecida del río Paraguay arrasa cultivos y amenaza al ganado en Humaitá', ''));
        $this->assertSame('hydrology', $classifier->classify('Pilar: cierran compuertas de estación de bombeos ante crecida del río Paraguay', ''));
        $this->assertNull($classifier->classify('Concepción: Río Paraguay sube 9 centímetros, pero no se reportan inundaciones', ''));
        $this->assertNull($classifier->classify('La crecida del río Paraguay no afecta a pobladores de Pilar', ''));
        $this->assertNull($classifier->classify('Río Paraguay registra nivel normal en Asunción', ''));
        $this->assertNull($classifier->classify('Río Paraguay sube 9 centímetros en Concepción', ''));
    }

    public function test_explicitly_denied_service_cuts_and_forensic_thirst_are_not_incidents(): void
    {
        $classifier = new PressIncidentClassifier;

        $this->assertNull($classifier->classify('No habrá cortes de agua en Asunción', ''));
        $this->assertNull($classifier->classify('ANDE informa que no hay cortes de energía eléctrica en Luque', ''));
        $this->assertNull($classifier->classify('No se reportan interrupciones del servicio eléctrico en Capiatá', ''));
        $this->assertNull($classifier->classify('No habrá interrupción del suministro de agua en Luque', ''));
        $this->assertNull($classifier->classify('Los vecinos no están sin energía eléctrica en Luque', ''));
        $this->assertNull($classifier->classify('El corredor quedó sin energía después de la carrera', ''));
        $this->assertNull($classifier->classify('Wenceslao Benoit llegó al límite en que un ser humano puede estar sin agua, revela forense', ''));
        $this->assertNull($classifier->classify('Quedarse sin agua en el desierto: una metáfora de la política', ''));
        $this->assertSame('electricity', $classifier->classify('No habrá cortes de agua, pero habrá un corte de luz en Luque', ''));
    }

    public function test_restoration_reports_keep_the_correct_utility_category_for_closure_review(): void
    {
        $classifier = new PressIncidentClassifier;

        $this->assertSame('water', $classifier->classify('Servicio de agua restablecido en Luque tras reparación', ''));
        $this->assertSame('water', $classifier->classify('ESSAP confirmó el restablecimiento del suministro de agua en Asunción', ''));
        $this->assertSame('water', $classifier->classify('Restablecieron el agua potable en Capiatá', ''));
        $this->assertSame('electricity', $classifier->classify('Suministro eléctrico restablecido en San Lorenzo', ''));
        $this->assertSame('electricity', $classifier->classify('ANDE confirma el restablecimiento del suministro de energía en Luque', ''));
        $this->assertSame('electricity', $classifier->classify('Normalizan el servicio eléctrico en Capiatá', ''));
        $this->assertNull($classifier->classify('Normalizan atención al cliente en oficinas de ESSAP', ''));
    }

    public function test_unconfirmed_restoration_alone_does_not_create_an_active_service_incident(): void
    {
        $classifier = new PressIncidentClassifier;

        $this->assertNull($classifier->classify('Prevén restablecimiento del suministro de agua en Luque', ''));
        $this->assertNull($classifier->classify('Trabajos para el restablecimiento del suministro de agua en Luque', ''));
        $this->assertNull($classifier->classify('ANDE informa restablecimiento del suministro de energía en Luque', ''));
        $this->assertNull($classifier->classify('El suministro eléctrico será restablecido en Luque', ''));
        $this->assertNull($classifier->classify('El suministro de agua no fue restablecido en Luque', ''));
        $this->assertSame('water', $classifier->classify('Vecinos siguen sin agua y esperan el restablecimiento del suministro en Luque', ''));
    }

    public function test_it_distinguishes_figurative_accidents_from_physical_crashes(): void
    {
        $classifier = new PressIncidentClassifier;

        $this->assertNull($classifier->classify('Peña es un accidente dentro del destino, dice dirigente colorado', 'El dirigente habló en Central.'));
        $this->assertNull($classifier->classify('Un accidente político y un choque de poderes en Asunción', ''));
        $this->assertNull($classifier->classify('Choque de opiniones en el Congreso', ''));
        $this->assertSame('traffic_accident', $classifier->classify('Dirigente político sufre accidente de tránsito en Central', ''));
        $this->assertSame('traffic_accident', $classifier->classify('Después del choque de opiniones, un vehículo volcó en un accidente vial', ''));
    }
}
