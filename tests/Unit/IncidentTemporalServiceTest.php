<?php

namespace Tests\Unit;

use App\Enums\TemporalAccuracy;
use App\Services\IncidentTemporalService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IncidentTemporalServiceTest extends TestCase
{
    #[DataProvider('occurrenceExpressions')]
    public function test_only_relative_expressions_linked_to_an_asserted_event_date_that_event(string $text, string $expected, string $accuracy): void
    {
        $result = app(IncidentTemporalService::class)->resolve($text, $this->publication());

        $this->assertSame($expected, $result['occurred_at']?->format('Y-m-d H:i'));
        $this->assertSame($accuracy, $result['accuracy']->value);
    }

    public static function occurrenceExpressions(): array
    {
        return [
            'explicit occurrence yesterday' => ['El accidente ocurrió ayer.', '2026-10-21 00:00', 'date_only'],
            'registered last night' => ['Se registró anoche un incendio.', '2026-10-21 22:00', 'approximate'],
            'event noun hours ago' => ['Accidente hace 2 horas en Luque.', '2026-10-22 12:30', 'relative_parsed'],
            'event verb hours ago' => ['El hecho ocurrió hace 3 horas.', '2026-10-22 11:30', 'relative_parsed'],
            'minutes ago' => ['Incendio hace 25 minutos.', '2026-10-22 14:05', 'relative_parsed'],
            'explicit clock' => ['El accidente ocurrió anoche cerca de las 22:15.', '2026-10-21 22:15', 'approximate'],
            'yesterday clock' => ['El hecho ocurrió ayer a las 18:20.', '2026-10-21 18:20', 'approximate'],
            'leading yesterday' => ['Ayer se registró un choque en Luque.', '2026-10-21 00:00', 'date_only'],
            'leading hours' => ['Hace 2 horas ocurrió un incendio.', '2026-10-22 12:30', 'relative_parsed'],
            'early morning' => ['Se produjo esta madrugada un accidente.', '2026-10-22 03:00', 'approximate'],
            'update does not replace occurrence' => ['El accidente ocurrió ayer. Actualizado hace 3 horas.', '2026-10-21 00:00', 'date_only'],
            'same clause update does not replace occurrence' => ['El accidente ocurrió ayer y la noticia fue actualizada hace 3 horas.', '2026-10-21 00:00', 'date_only'],
            'repeated title does not cause ambiguity' => ['Accidente hace 2 horas. Accidente hace 2 horas en Luque.', '2026-10-22 12:30', 'relative_parsed'],
        ];
    }

    #[DataProvider('contextualExpressions')]
    public function test_contextual_ambiguous_or_ongoing_expressions_preserve_unknown_occurrence(string $text): void
    {
        $result = app(IncidentTemporalService::class)->resolve($text, $this->publication());

        $this->assertNull($result['occurred_at']);
        $this->assertSame(TemporalAccuracy::Unknown, $result['accuracy']);
        $this->assertNull($result['evidence']);
    }

    public static function contextualExpressions(): array
    {
        return [
            'real rain accumulation context' => ['Sistema de tormentas ingresó a Itapúa. El acumulado de lluvias alcanza los 69 mm en la costanera desde ayer.'],
            'accumulation without since' => ['Se registró un acumulado de lluvias ayer de 69 mm.'],
            'leading accumulation' => ['Ayer se registró un acumulado de lluvias de 69 mm.'],
            'article update' => ['Incendio en Luque. Actualizado hace 3 horas.'],
            'same clause article update' => ['El accidente fue actualizado hace 3 horas.'],
            'publication' => ['La noticia del accidente fue publicada ayer.'],
            'ongoing last night' => ['El incendio permanece activo desde anoche.'],
            'ongoing yesterday' => ['La falta de agua afecta al barrio desde ayer.'],
            'ongoing hours' => ['El corte de agua afecta al barrio desde hace 2 horas.'],
            'measurement update' => ['La medición se actualizó hace 3 horas.'],
            'announcement date' => ['La alerta fue emitida ayer.'],
            'negative assertion' => ['No se registró ningún accidente ayer.'],
            'no event anchor' => ['El comunicado fue presentado anoche.'],
            'different events' => ['El accidente ocurrió ayer. Un incendio ocurrió hace 2 horas.'],
            'different clock times' => ['El accidente ocurrió ayer a las 18:00. Otro accidente ocurrió ayer a las 21:00.'],
        ];
    }

    public function test_explicit_metadata_remains_authoritative_even_with_contextual_text(): void
    {
        $result = app(IncidentTemporalService::class)->resolve('La noticia fue actualizada hace 3 horas y el acumulado se mide desde ayer.', $this->publication(), [
            'occurred_at' => '2026-10-20T18:45:00-03:00', 'occurred_at_accuracy' => 'exact',
        ]);

        $this->assertSame('2026-10-20T18:45:00-03:00', $result['occurred_at']?->toIso8601String());
        $this->assertSame(TemporalAccuracy::Exact, $result['accuracy']);
    }

    public function test_relative_expressions_without_a_publication_reference_remain_unknown(): void
    {
        $result = app(IncidentTemporalService::class)->resolve('El accidente ocurrió ayer.', null);
        $this->assertNull($result['occurred_at']);
    }

    private function publication(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-22 14:30:00 America/Asuncion');
    }
}
