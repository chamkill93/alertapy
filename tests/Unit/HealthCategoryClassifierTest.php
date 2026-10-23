<?php

namespace Tests\\Unit;

use App\\Services\\HealthCategoryClassifier;
use App\\Services\\PressIncidentClassifier;
use PHPUnit\\Framework\\TestCase;

class HealthCategoryClassifierTest extends TestCase
{
    public function test_it_separates_vector_control_from_public_health_and_correlates_epidemics(): void
    {
        $classifier = new HealthCategoryClassifier;

        $this->assertSame(['health_vector'], $classifier->classifyAll('SENEPA realiza minga ambiental en Luque'));
        $this->assertSame(['health_vector', 'health_public'], $classifier->classifyAll('Confirman brote de dengue en Boquerón'));
        $this->assertSame(['health_public'], $classifier->classifyAll('Asistencia en salud llega a familias afectadas en Alto Paraguay'));
        $this->assertSame(['health_public'], $classifier->classifyAll(
            'Equipos de salud asisten a comunidades afectadas por inundaciones en Misiones',
        ));
        $this->assertSame(['health_public'], $classifier->classifyAll(
            'Servicios de salud de Amambay registran más de 53 mil atenciones en septiembre',
        ));
        $this->assertSame(['health_public'], $classifier->classifyAll(
            'Salud Pública provee servicio médico a más de 400 familias de comunidades rurales de Presidente Hayes',
        ));
        $this->assertSame(['health_public'], $classifier->classifyAll(
            'Confirman casos de hantavirus en Boquerón',
        ));
        $this->assertSame(['health_public'], $classifier->classifyAll(
            'Paciente recibe alta tras trasplante renal número 211 en Clínicas',
        ));
        $this->assertSame(['health_vector', 'health_public'], $classifier->classifyAll(
            'Salud reporta diez semanas sin dengue, pero en tres regiones aumentan las sospechas',
        ));
        $this->assertNull($classifier->classify('Capacitación administrativa del MSPBS en Asunción'));
    }

    public function test_health_notice_can_be_correlated_with_a_separate_territorial_layer(): void
    {
        $title = 'Asistencia en salud llega a familias afectadas por crecidas en Ciudad del Este';
        $categories = array_values(array_unique([
            ...(new HealthCategoryClassifier)->classifyAll($title),
            ...(new PressIncidentClassifier)->classifyAll($title, ''),
        ]));

        $this->assertSame(['health_public', 'weather_flood'], $categories);
    }

    public function test_agencia_ip_flood_assistance_story_populates_health_and_flood_layers(): void
    {
        $title = 'Más de 200 damnificados reciben asistencia médica en zonas afectadas por inundaciones en Ñeembucú';
        $categories = array_values(array_unique([
            ...(new HealthCategoryClassifier)->classifyAll($title),
            ...(new PressIncidentClassifier)->classifyAll($title, ''),
        ]));

        $this->assertSame(['health_public', 'weather_flood'], $categories);
    }

    public function test_tvs_itapua_health_story_during_floods_populates_both_layers(): void
    {
        $title = 'VII Región Sanitaria coordina atención médica y fumigaciones para familias afectadas por inundaciones';
        $categories = array_values(array_unique([
            ...(new HealthCategoryClassifier)->classifyAll($title),
            ...(new PressIncidentClassifier)->classifyAll($title, ''),
        ]));

        $this->assertSame(['health_public', 'weather_flood'], $categories);
    }
}
