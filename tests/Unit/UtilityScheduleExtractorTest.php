<?php

namespace Tests\Unit;

use App\Services\UtilityScheduleExtractor;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UtilityScheduleExtractorTest extends TestCase
{
    public function test_it_extracts_the_explicit_palacio_de_justicia_schedule_without_using_publication_as_start(): void
    {
        $text = 'El suministro de energía eléctrica en las dos torres del Palacio de Justicia de Asunción será interrumpido este sábado 24 de octubre, de 08:00 a 11:00, debido a tareas de mantenimiento en la Estación Puerto Sajonia.';
        $result = (new UtilityScheduleExtractor)->extract($text."\n".$text, 'electricity', CarbonImmutable::parse('2026-10-20T21:15:00-03:00'));

        $this->assertSame('2026-10-24T08:00:00-03:00', $result['starts_at']);
        $this->assertSame('2026-10-24T11:00:00-03:00', $result['ends_at']);
        $this->assertSame($result['starts_at'], $result['occurred_at']);
        $this->assertSame('exact', $result['occurred_at_accuracy']);
        $this->assertSame($text, $result['schedule_evidence']);
    }

    public function test_it_accepts_an_explicit_water_schedule_and_an_unrelated_business_schedule_does_not_replace_it(): void
    {
        $result = (new UtilityScheduleExtractor)->extract(
            'Habrá un corte de agua potable el 24 de octubre de 2026, entre las 07:30 y las 12:15. La oficina comercial abre el 24 de octubre de 08:00 a 16:00.',
            'water', CarbonImmutable::parse('2026-10-20T18:00:00-03:00'),
        );
        $this->assertSame('2026-10-24T07:30:00-03:00', $result['starts_at']);
        $this->assertSame('2026-10-24T12:15:00-03:00', $result['ends_at']);
    }

    public function test_the_nearby_year_is_resolved_from_publication_across_new_year(): void
    {
        $result = (new UtilityScheduleExtractor)->extract(
            'Corte de energía el 2 de enero, desde las 08:00 hasta las 11:00.',
            'electricity', CarbonImmutable::parse('2026-12-30T18:00:00-03:00'),
        );
        $this->assertSame('2027-01-02T08:00:00-03:00', $result['starts_at']);
    }

    #[DataProvider('ambiguousSchedules')]
    public function test_it_rejects_missing_ambiguous_invalid_or_unrelated_schedules(string $text, string $category = 'electricity'): void
    {
        $this->assertSame([], (new UtilityScheduleExtractor)->extract($text, $category, CarbonImmutable::parse('2026-10-20T21:15:00-03:00')));
    }

    public static function ambiguousSchedules(): array
    {
        return [
            'relative day' => ['El corte de energía será mañana de 08:00 a 11:00.'],
            'weekday only' => ['Corte de energía este sábado, de 08:00 a 11:00.'],
            'missing hours' => ['Corte de energía el 24 de octubre.'],
            'business hours only' => ['Corte de energía afecta Asunción. La oficina comercial atiende el 24 de octubre de 08:00 a 11:00.'],
            'business hours same sentence' => ['Por el corte de energía, la oficina tendrá horario de atención el 24 de octubre de 08:00 a 11:00.'],
            'different dates' => ['Corte de energía el 24 de octubre de 08:00 a 11:00. Otro corte de energía el 25 de octubre de 09:00 a 12:00.'],
            'date list' => ['Cortes de energía el 24 y 25 de octubre de 08:00 a 11:00.'],
            'date list abbreviated at end' => ['Cortes de energía el 24 de octubre y el 25 de 08:00 a 11:00.'],
            'different intervals' => ['Corte de energía el 24 de octubre de 08:00 a 11:00 y de 13:00 a 16:00.'],
            'second event without date' => ['Corte de energía el 24 de octubre de 08:00 a 11:00. En Luque habrá otro corte de energía de 09:00 a 12:00.'],
            'unrelated category' => ['Corte de energía el 31 de octubre de 2026 de 08:00 a 11:00.', 'traffic_accident'],
            'impossible calendar date' => ['Corte de energía el 31 de noviembre de 2026 de 08:00 a 11:00.'],
            'wrong weekday' => ['Corte de energía el jueves 24 de octubre de 08:00 a 11:00.'],
            'invalid hour' => ['Corte de energía el 24 de octubre de 25:00 a 27:00.'],
            'invalid minute' => ['Corte de energía el 24 de octubre de 08:75 a 11:00.'],
            'overnight ambiguous' => ['Corte de energía el 24 de octubre de 23:00 a 02:00.'],
            'old year' => ['Corte de energía el 24 de octubre de 2025 de 08:00 a 11:00.'],
            'far inferred date' => ['Corte de energía el 24 de diciembre de 08:00 a 11:00.'],
            'cancelled' => ['No habrá corte de energía el 24 de octubre de 08:00 a 11:00.'],
        ];
    }
}
