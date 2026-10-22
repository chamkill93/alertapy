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

class SenadCollector implements CollectorInterface
{
    public function __construct(private readonly ParaguayLocationExtractor $locationExtractor) {}

    /** @return Collection<int, CandidateDTO> */
    public function collect(Source $source): Collection
    {
        $settings = (array) config('alertapy.sources.senad', []);
        $lookbackDays = (int) ($settings['lookback_days'] ?? 0);
        $now = CarbonImmutable::now((string) config('app.timezone'));
        $cutoff = $lookbackDays > 0
            ? $now->startOfDay()->subDays($lookbackDays)
            : $now->subHours((int) ($settings['max_age_hours'] ?? 168));
        $url = (string) (($settings['listing_urls'][0] ?? null) ?: $source->base_url);
        $response = Http::accept('text/html')
            ->withUserAgent((string) config('alertapy.http.user_agent'))
            ->timeout((int) config('alertapy.http.timeout', 15))
            ->retry(2, 300, throw: false)
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("{$source->name} respondió HTTP {$response->status()} al consultar sus operativos.");
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->body(), LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $items = $xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " item ")]');
        $candidates = collect();

        if ($items === false) {
            return $candidates;
        }

        foreach ($items as $item) {
            if (! $item instanceof DOMElement) {
                continue;
            }

            $anchor = $xpath->query('.//div[contains(@class,"item-title")]//a[@href]', $item)?->item(0);
            $title = $this->plainText((string) $xpath->query('.//div[contains(@class,"item-title")]//h2', $item)?->item(0)?->textContent);
            $dateText = $this->plainText((string) $xpath->query('.//div[contains(@class,"item-date")]', $item)?->item(0)?->textContent);
            $summary = $this->plainText((string) $xpath->query('.//div[contains(@class,"item-summary")]', $item)?->item(0)?->textContent);

            if (! $anchor instanceof DOMElement || $title === '' || $dateText === '') {
                continue;
            }

            $publishedAt = CarbonImmutable::createFromFormat('!d/m/Y', $dateText, (string) config('app.timezone'));

            if ($publishedAt === false || $publishedAt->lt($cutoff)) {
                continue;
            }

            $eventTitle = Str::lower(Str::ascii($title));

            if (preg_match('/\b(operativo|allanamiento|detenid|captur|desarticul|incaut|decomis|desmantel|destruy|localiz|neutraliz|aprehend|intercept)\w*/u', $eventTitle) !== 1) {
                continue;
            }

            $location = $this->locationExtractor->extract($title, $summary);
            $originalUrl = trim($anchor->getAttribute('href'));

            if ($location === null || ! str_starts_with($originalUrl, 'https://senad.gov.py/')) {
                continue;
            }

            $candidates->push(new CandidateDTO(
                sourceSlug: $source->slug,
                sourceType: $source->type,
                externalIdentifier: hash('sha256', $originalUrl),
                title: $title,
                originalUrl: $originalUrl,
                content: $title,
                publishedAt: $publishedAt,
                detectedLocation: $location['query'],
                metadata: [
                    'category_slug' => 'security',
                    'location_text' => $location['text'],
                    'city' => $location['city'],
                    'department' => $location['department'],
                    'location_accuracy' => $location['accuracy'],
                    'original_title' => $title,
                    'privacy_limited' => true,
                ],
            ));
        }

        return $candidates->take(max(1, (int) ($settings['max_candidates'] ?? 25)));
    }

    private function plainText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '');
    }
}
