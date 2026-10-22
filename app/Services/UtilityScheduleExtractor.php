<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Extract only a single explicitly dated, timed utility interruption. */
class UtilityScheduleExtractor
{
    private const MONTHS = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];

    private const DATE = '/\b(?:(lunes|martes|miercoles|jueves|viernes|sabado|domingo)\s*,?\s*)?(\d{1,2})\s+de\s+(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)(?:\s+(?:de(?:l)?\s+)?(\d{4}))?\b/u';

    private const RANGE = '/\b(?:de(?:sde)?|entre)\s+(?:las?\s+)?(\d{1,2}):(\d{2})\s*(?:h(?:s|oras)?\.?\s*)?(?:a|hasta|y)\s+(?:las?\s+)?(\d{1,2}):(\d{2})\b/u';

    /**
     * Times are interpreted in Paraguay. A missing year is accepted only within
     * one day before to 31 days after publication, never from the current clock.
     *
     * @return array<string, string>
     */
    public function extract(string $text, string $category, CarbonImmutable $publishedAt): array
    {
        if (! in_array($category, ['electricity', 'water'], true)) {
            return [];
        }

        $service = $category === 'electricity'
            ? '/\b(?:energia|electricidad|luz|electrico|ande)\b/u'
            : '/\b(?:agua|essap)\b/u';
        $reference = $publishedAt->setTimezone('America/Asuncion')->startOfDay();
        $dates = $intervals = [];
        $incompleteRange = false;
        $sentences = preg_split('/(?<=[.!?])\s+|[\r\n]+/u', $text) ?: [];

        foreach ($sentences as $sentence) {
            $plain = trim(preg_replace('/\s+/u', ' ', Str::lower(Str::ascii($sentence))) ?? '');
            if (preg_match($service, $plain) !== 1
                || preg_match('/\b(?:cortes?|interrupcion(?:es)?|interrump\w*|suspension(?:es)?|suspend\w*|sin (?:agua|luz|energia|electricidad))\b/u', $plain) !== 1) {
                continue;
            }
            if (preg_match('/\b(?:horario(?:s)? (?:comercial\w*|de atencion)|atencion al publico|conferencia|rueda de prensa)\b/u', $plain) === 1) {
                continue;
            }
            if (preg_match('/\b(?:no (?:habra|hay|hubo)|cancelad[oa]|cancelaron|cancela)\b/u', $plain) === 1
                || preg_match('/\b\d{1,2}\s*(?:y|al?|-)\s*(?:(?:el|lunes|martes|miercoles|jueves|viernes|sabado|domingo)\s+)?\d{1,2}\s+de\b/u', $plain) === 1) {
                return [];
            }

            preg_match_all(self::DATE, $plain, $dateMatches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            preg_match_all(self::RANGE, $plain, $ranges, PREG_SET_ORDER);
            $sentenceDates = [];
            foreach ($dateMatches as $match) {
                if (preg_match('/'.preg_quote($match[0], '/').'\s+(?:y|al?)\s+(?:el\s+)?\d{1,2}\b/u', $plain) === 1) {
                    return [];
                }
                $date = $this->date($match, $reference);
                if ($date === null) {
                    return [];
                }
                $dates[$date->toDateString()] = $date;
                $sentenceDates[$date->toDateString()] = $date;
            }
            if (count($dates) > 1 || count($ranges) > 1) {
                return [];
            }
            if ($ranges === []) {
                continue;
            }
            if (count($sentenceDates) !== 1) {
                $incompleteRange = true;

                continue;
            }
            $range = $ranges[0];
            if ((int) $range[1] > 23 || (int) $range[2] > 59 || (int) $range[3] > 23 || (int) $range[4] > 59) {
                return [];
            }
            $date = reset($sentenceDates);
            $start = $date->setTime((int) $range[1], (int) $range[2]);
            $end = $date->setTime((int) $range[3], (int) $range[4]);
            if ($end->lessThanOrEqualTo($start)) {
                return [];
            }
            $intervals[$start->toIso8601String().'|'.$end->toIso8601String()] = [
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
                'occurred_at' => $start->toIso8601String(),
                'occurred_at_accuracy' => 'exact',
                'schedule_evidence' => mb_substr(trim($sentence), 0, 600),
            ];
        }

        return ! $incompleteRange && count($intervals) === 1 ? reset($intervals) : [];
    }

    /** @param array<int, ?string> $match */
    private function date(array $match, CarbonImmutable $reference): ?CarbonImmutable
    {
        $month = self::MONTHS[$match[3]];
        $day = (int) $match[2];
        $years = $match[4] !== null ? [(int) $match[4]] : [$reference->year - 1, $reference->year, $reference->year + 1];
        $weekdays = ['lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7];

        foreach ($years as $year) {
            if (! checkdate($month, $day, $year)) {
                continue;
            }
            $date = CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'America/Asuncion');
            if ($date->betweenIncluded($reference->subDay(), $reference->addDays(31))
                && ($match[1] === null || $date->isoWeekday() === $weekdays[$match[1]])) {
                return $date;
            }
        }

        return null;
    }
}
