<?php

namespace App\Collectors;

use App\Contracts\Collectors\CollectorInterface;
use App\DTOs\CandidateDTO;
use App\Models\Source;
use App\Services\ParaguayLocationExtractor;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SiaguapyHydrologyCollector implements CollectorInterface
{
    // Match complete official state labels, never a keyword in a negated or
    // explanatory phrase such as "Sin alerta" or "No hay peligro".
    private const ALERT_STATES = [
        'alerta', 'critico', 'critica', 'desastre', 'precaucion',
        'bombeo escalonado', 'cese de bombeo', 'cese bombeo',
    ];

    public function __construct(private readonly ParaguayLocationExtractor $locationExtractor) {}

    /** @return Collection<int, CandidateDTO> */
    public function collect(Source $source): Collection
    {
        $url = (string) config('alertapy.sources.siaguapy.alerts_url');
        $response = Http::accept('text/html, application/xhtml+xml;q=0.9')
            ->withUserAgent((string) config('alertapy.http.user_agent'))
            ->timeout((int) config('alertapy.http.timeout', 15))
            ->retry(2, 300)
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("SIAGUAPY respondió HTTP {$response->status()} al consultar alertas hidrológicas.");
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->body(), LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $rows = (new DOMXPath($document))->query('//table//tr[td]');

        if ($rows === false || $rows->length === 0) {
            throw new RuntimeException('SIAGUAPY no devolvió la tabla de estaciones en el formato esperado.');
        }

        $cutoff = CarbonImmutable::now((string) config('app.timezone'))
            ->subHours((int) config('alertapy.sources.siaguapy.max_age_hours', 72));
        $candidates = collect();

        foreach ($rows as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $cells = [];

            foreach ($row->getElementsByTagName('td') as $cell) {
                $cells[] = $this->plainText($cell->textContent);
            }

            if (count($cells) < 4) {
                continue;
            }

            $station = $cells[0];
            $publishedAt = $this->date($cells[1]);
            $state = trim((string) end($cells));
            $normalizedState = Str::lower(Str::ascii($state));
            $location = $this->locationExtractor->extract($station);

            if ($station === '' || $state === ''
                || ! in_array($normalizedState, self::ALERT_STATES, true)
                || $publishedAt === null
                || $publishedAt->lt($cutoff)
                || $publishedAt->isFuture()) {
                continue;
            }

            // Preserve a genuine alert even if the station is not in the local
            // place catalog. The ingestor will keep it pending if geocoding fails.
            $location ??= [
                'text' => $station, 'query' => $station.', Paraguay',
                'city' => null, 'department' => null, 'accuracy' => 'approximate',
            ];

            $level = $cells[2] ?? 'sin dato';
            $title = "Alerta hidrológica en {$location['text']}: {$state}";

            $candidates->push(new CandidateDTO(
                sourceSlug: $source->slug,
                sourceType: $source->type,
                externalIdentifier: 'siaguapy-'.hash('sha256', $station.'|'.$publishedAt->toDateString().'|'.$state),
                title: $title,
                originalUrl: $url,
                content: "Estación {$station}. Nivel: {$level}. Estado informado por SIAGUAPY: {$state}.",
                publishedAt: $publishedAt,
                detectedLocation: $location['query'],
                metadata: [
                    'category_slug' => 'hydrology',
                    'location_text' => $location['text'],
                    'city' => $location['city'],
                    'department' => $location['department'],
                    'location_accuracy' => $location['accuracy'],
                    'original_title' => $title,
                    'official_hydrology_alert' => true,
                ],
            ));
        }

        return $candidates;
    }

    private function plainText(string $value): string
    {
        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?: $text);
    }

    private function date(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['d/m/y', 'd/m/Y', 'Y-m-d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value, (string) config('app.timezone'));
                if ($date !== null && $date->format($format) === $value) {
                    return $date;
                }
            } catch (\Throwable) {
                // Try the next documented date format; never infer missing dates.
            }
        }

        return null;
    }
}
