<?php

namespace Tests\Unit;

use App\Services\HealthCategoryClassifier;
use App\Services\PressIncidentClassifier;
use PHPUnit\Framework\TestCase;

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
}

