<?php

namespace App\Collectors;

use App\Contracts\Collectors\CollectorInterface;
use App\DTOs\CandidateDTO;
use App\Models\Source;
use App\Services\ParaguayLocationExtractor;
use App\Services\PressIncidentClassifier;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MinisterioPublicoCollector implements CollectorInterface
{
    public function __construct(
        private readonly ParaguayLocationExtractor $locationExtractor,
        private readonly PressIncidentClassifier $classifier,
    ) {}

    /** @return Collection<int, CandidateDTO> */
    public function collect(Source $source): Collection
    {
        $settings = (array) config('alertapy.sources.ministerio-publico', []);
        $now = CarbonImmutable::now((string) config('app.timezone'));
        $lookbackDays = (int) ($settings['lookback_days'] ?? 0);
        $cutoff = $lookbackDays > 0
            ? $now->startOfDay()->subDays($lookbackDays)
            : $now->subHours((int) ($settings['max_age_hours'] ?? 168));
        $candidates = collect();

        foreach ($this->archiveUrls($now, (string) ($settings['archive_endpoint'] ?? '')) as $url) {
            $request = Http::accept('text/html')
                ->withUserAgent((string) config('alertapy.http.user_agent'))
                ->timeout((int) config('alertapy.http.timeout', 15))
                ->retry(2, 300, throw: false);
            $caBundle = $settings['tls_ca_bundle'] ?? null;

            if (is_string($caBundle) && is_file($caBundle)) {
                $request = $request->withOptions(['verify' => $caBundle]);
            }

            $response = $request->get($url);

            if (! $response->successful()) {
                throw new RuntimeException("{$source->name} respondió HTTP {$response->status()} al consultar su archivo.");
            }

            foreach ($this->parse($source, $response->body(), $cutoff) as $candidate) {
                $candidates->put($candidate->externalIdentifier, $candidate);
            }
        }

        return $candidates->values()->take(max(1, (int) ($settings['max_candidates'] ?? 25)));
    }

    /** @return Collection<int, CandidateDTO> */
    private function parse(Source $source, string $html, CarbonImmutable $cutoff): Collection
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $items = $xpath->query('//li[.//h2 and .//*[contains(concat(" ", normalize-space(@class), " "), " post-meta ")]]');
        $candidates = collect();

        if ($items === false) {
            return $candidates;
        }

        foreach ($items as $item) {
            if (! $item instanceof DOMElement) {
                continue;
            }

            $anchor = $xpath->query('.//a[@href][.//h2]', $item)?->item(0);
            $dateText = trim((string) $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " post-meta ")]', $item)?->item(0)?->textContent);
            $title = $this->plainText((string) $xpath->query('.//h2', $item)?->item(0)?->textContent);

            if (! $anchor instanceof DOMElement || $title === '') {
                continue;
            }

            $publishedAt = CarbonImmutable::createFromFormat('!d/m/Y', $dateText, (string) config('app.timezone'));

            if ($publishedAt === false || $publishedAt->lt($cutoff)) {
                continue;
            }

            $normalizedTitle = Str::lower(Str::ascii($title));

            if (preg_match('/\b(condena(?:n|do|da)?|sentencia|pena privativa|prision preventiva|juicio oral|acusacion)\b/u', $normalizedTitle) === 1) {
                continue;
            }

            $location = $this->locationExtractor->extract($title);
            $category = $this->classifier->classify($title, '');

            if ($location === null || $category === null) {
                continue;
            }

            $url = trim($anchor->getAttribute('href'));

            if (! str_starts_with($url, 'https://ministeriopublico.gov.py/nota/')) {
                continue;
            }

            $candidates->push(new CandidateDTO(
                sourceSlug: $source->slug,
                sourceType: $source->type,
                externalIdentifier: hash('sha256', $url),
                title: $title,
                originalUrl: $url,
                content: $title,
                publishedAt: $publishedAt,
                detectedLocation: $location['query'],
                metadata: [
                    'category_slug' => $category,
                    'location_text' => $location['text'],
                    'city' => $location['city'],
                    'department' => $location['department'],
                    'location_accuracy' => $location['accuracy'],
                    'original_title' => $title,
                    'privacy_limited' => true,
                ],
            ));
        }

        return $candidates;
    }

    /** @return array<int, string> */
    private function archiveUrls(CarbonImmutable $now, string $template): array
    {
        $monthNames = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];

        return collect([$now->startOfMonth(), $now->subMonthNoOverflow()->startOfMonth()])
            ->map(fn (CarbonImmutable $month): string => strtr($template, [
                '{year}' => $month->format('Y'),
                '{month}' => $monthNames[$month->month],
            ]))
            ->all();
    }

    private function plainText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '');
    }
}
