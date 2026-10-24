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
        // Labor disputes that threaten laboratory or clinical coverage are
        // health-service events even when a headline omits the hospital name.
        $this->assertSame(
            ['health_public'],
            $classifier->classifyAll('Bioquímicos confirman huelga de tres días tras fracasar la mesa tripartita con Salud y Trabajo'),
        );
        $this->assertSame(
            ['health_public'],
            $classifier->classifyAll('Huelga de médicos suspenderá consultas ambulatorias en hospitales públicos'),
        );
        $this->assertNull($classifier->classify('Huelga de funcionarios del transporte público'));
        $this->assertSame(['health_public'], $classifier->classifyAll(
            'Equipos de salud asisten a comunidades afectadas por inundaciones en Misiones',
        ));
        $this->assertSame(['health_public'], $classifier->classifyAll(
            'Coronel Bogado realizó jornada de concienciación y reconocimiento en el marco de Octubre Rosa',
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
        $this->assertSame(['health_vector', 'health_public'], $classifier->classifyAll(
            'Vacunación dengue: ya recibiste la primera dosis, no te olvides de la segunda',
        ));
        $this->assertSame(['health_public'], $classifier->classifyAll(
            'Realizan charla de concienciación sobre la prevención del cáncer de mama en la DNERHS',
        ));
        $this->assertSame(['health_public'], $classifier->classifyAll('Máquina de rayos X para Puerto Casado'));
        $this->assertSame(['health_public'], $classifier->classifyAll('Traslado de pacientes para estudios de mamografía en Alto Paraguay'));
        $this->assertNull($classifier->classify('Capacitación administrativa del MSPBS en Asunción'));
    }

    public function test_it_classifies_respiratory_virus_reports_as_public_health(): void
    {
        $classifier = new HealthCategoryClassifier;

        foreach (['Rhinovirus', 'Rinovirus', 'Metapneumovirus', 'Adenovirus', 'Parainfluenza', 'Virus sincitial respiratorio', 'VRS', 'RSV'] as $pathogen) {
            $this->assertSame(['health_public'], $classifier->classifyAll($pathogen));
        }

        $this->assertSame(
            ['health_public'],
            $classifier->classifyAll('Rhinovirus causa más de la mitad de las internaciones por cuadros respiratorios'),
        );
    }

    public function test_it_uses_the_article_lead_to_correlate_health_topics(): void
    {
        $classifier = new HealthCategoryClassifier;

        $this->assertSame(
            ['health_vector', 'health_public'],
            $classifier->classifyAll(
                'Dengue: tras lluvias se insta a reforzar la prevención de criaderos',
                'El reporte de vigilancia epidemiológica indica casos sospechosos y actualiza la situación sanitaria.',
            ),
        );
        $this->assertSame(
            ['health_public'],
            $classifier->classifyAll(
                'Jornada comunitaria en Caaguazú',
                'La brigada realizó vacunación y atención médica en la región.',
            ),
        );
        $this->assertSame(
            ['health_vector'],
            $classifier->classifyAll(
                'SENEPA realiza minga ambiental en Luque',
                'El operativo eliminó criaderos y brindó recomendaciones a los vecinos.',
            ),
        );
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
