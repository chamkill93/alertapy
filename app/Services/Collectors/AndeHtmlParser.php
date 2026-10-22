<?php

namespace App\Services\Collectors;

use App\DTOs\CandidateDTO;
use App\Enums\SourceType;
use App\Services\ParaguayLocationExtractor;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AndeHtmlParser
{
    public function __construct(private readonly ParaguayLocationExtractor $locationExtractor) {}

    /** @return array<int, array{id: string, title: string, url: string}> */
    public function articleLinks(string $html, string $baseUrl): array
    {
        $xpath = $this->xpath($html);
        $links = [];

        foreach ($xpath->query('//a[contains(@href, "interna.php?id=")]') ?: [] as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $title = $this->clean($anchor->textContent);
            $href = trim($anchor->getAttribute('href'));

            if (! Str::contains(Str::lower(Str::ascii($title)), 'trabajos programados')
                || preg_match('/[?&]id=(\d+)/', $href, $matches) !== 1) {
                continue;
            }

            $links[$matches[1]] = [
                'id' => $matches[1],
                'title' => $title,
                'url' => str_starts_with($href, 'http') ? $href : rtrim($baseUrl, '/').'/'.ltrim($href, '/'),
            ];
        }

        return array_values($links);
    }

    /** @return Collection<int, CandidateDTO> */
    public function articleCandidates(string $html, string $url, string $articleId, string $fallbackTitle = ''): Collection
    {
        $xpath = $this->xpath($html);
        $heading = $xpath->query('//h1')?->item(0)?->textContent;
        $articleTitle = $this->clean((string) ($heading ?: $fallbackTitle));
        $body = $this->clean((string) $xpath->query('//body')?->item(0)?->textContent);
        $zoneStart = mb_stripos($body, 'ZONA ');

        if ($zoneStart === false) {
            return collect();
        }

        $zoneText = mb_substr($body, $zoneStart);
        $officeStart = mb_stripos($zoneText, 'Oficina de Comunicaciones');
        if ($officeStart !== false) {
            $zoneText = mb_substr($zoneText, 0, $officeStart);
        }

        $eventDate = $this->eventDateFromTitle($articleTitle);
        $zones = preg_split('/(?=ZONA\s+\d{1,2}\s*:)/iu', $zoneText, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($zones)->map(function (string $zone) use ($articleTitle, $articleId, $url, $eventDate): ?CandidateDTO {
            if (preg_match('/^ZONA\s+(\d{1,2})\s*:\s*(.*?)(?=HORARIO\s+DE\s+TRABAJO\s*:)/isu', trim($zone), $locationMatch) !== 1) {
                return null;
            }

            $zoneNumber = str_pad($locationMatch[1], 2, '0', STR_PAD_LEFT);
            $locationText = $this->clean($locationMatch[2]);
            $location = $this->locationExtractor->extract(
                $this->municipalityHint($locationText),
                $locationText,
            );

            if ($location === null || $eventDate === null) {
                return null;
            }

            preg_match('/HORARIO\s+DE\s+TRABAJO\s*:\s*De\s*(\d{1,2}:\d{2})\s*a\s*(\d{1,2}:\d{2})/iu', $zone, $hours);
            preg_match('/ACTIVIDAD\s*:\s*(.*?)(?=UNIDAD\s+RESPONSABLE\s*:|$)/isu', $zone, $activityMatch);
            $startsAt = isset($hours[1]) ? $this->atTime($eventDate, $hours[1]) : $eventDate->startOfDay();
            $endsAt = isset($hours[2]) ? $this->atTime($eventDate, $hours[2]) : null;

            if ($endsAt !== null && $endsAt->lte($startsAt)) {
                $endsAt = $endsAt->addDay();
            }

            $activity = $this->clean($activityMatch[1] ?? 'Trabajo programado sobre la red eléctrica.');

            return new CandidateDTO(
                sourceSlug: 'ande',
                sourceType: SourceType::Official,
                externalIdentifier: "{$articleId}-zone-{$zoneNumber}",
                title: "Trabajo programado de ANDE en {$location['text']}",
                originalUrl: $url,
                content: trim($zone),
                publishedAt: null,
                detectedLocation: $location['query'],
                metadata: [
                    'category_slug' => 'electricity',
                    'location_text' => $locationText,
                    'city' => $location['city'],
                    'department' => $location['department'],
                    'location_accuracy' => $location['accuracy'],
                    'original_title' => $articleTitle,
                    'starts_at' => $startsAt->toIso8601String(),
                    'ends_at' => $endsAt?->toIso8601String(),
                    'activity' => $activity,
                ],
            );
        })->filter()->values();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    public function eventDateFromTitle(string $title): ?CarbonImmutable
    {
        $months = [
            'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
            'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
            'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
            'noviembre' => 11, 'diciembre' => 12,
        ];
        $normalized = Str::lower(Str::ascii($title));

        if (preg_match('/(\d{1,2})\s+de\s+([a-z]+)\s+de\s+(\d{4})/', $normalized, $matches) !== 1
            || ! isset($months[$matches[2]])) {
            return null;
        }

        return CarbonImmutable::create(
            (int) $matches[3],
            $months[$matches[2]],
            (int) $matches[1],
            0,
            0,
            0,
            (string) config('app.timezone'),
        );
    }

    private function atTime(CarbonImmutable $date, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $date->setTime($hour, $minute);
    }

    private function clean(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?: $value);
    }

    private function municipalityHint(string $locationText): string
    {
        $parts = preg_split('/\s+[–-]\s+/u', $locationText) ?: [];
        $last = trim((string) end($parts));

        return $last !== '' ? $last : $locationText;
    }
}
