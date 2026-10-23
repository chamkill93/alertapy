<?php

namespace App\Collectors;

use App\Contracts\Collectors\CollectorInterface;
use App\DTOs\CandidateDTO;
use App\Models\Source;
use App\Services\HealthCategoryClassifier;
use App\Services\ParaguayLocationExtractor;
use App\Services\PressIncidentClassifier;
use App\Services\UtilityScheduleExtractor;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PressHomepageCollector implements CollectorInterface
{
    public function __construct(
        private readonly ParaguayLocationExtractor $locationExtractor,
        private readonly PressIncidentClassifier $classifier,
        private readonly UtilityScheduleExtractor $schedules,
        private readonly HealthCategoryClassifier $healthClassifier,
    ) {}

    /** @return Collection<int, CandidateDTO> */
    public function collect(Source $source): Collection
    {
        $settings = array_replace_recursive(
            (array) config("alertapy.sources.{$source->slug}", []),
            (array) ($source->configuration_json ?? []),
        );
        $listingUrls = array_values(array_unique(array_map(
            fn (mixed $url): string => $this->dynamicListingUrl((string) $url),
            array_filter((array) ($settings['listing_urls'] ?? [$source->base_url])),
        )));
        $maximumLinks = max(1, (int) ($settings['max_links'] ?? 120));
        $maximumCandidates = max(1, (int) ($settings['max_candidates'] ?? 30));
        $prefilterRequiresLocation = (bool) ($settings['prefilter_requires_location'] ?? true);
        $lookbackDays = (int) ($settings['lookback_days'] ?? 0);
        $cutoff = $lookbackDays > 0
            ? CarbonImmutable::now((string) config('app.timezone'))->startOfDay()->subDays($lookbackDays)
            : CarbonImmutable::now((string) config('app.timezone'))->subHours((int) ($settings['max_age_hours'] ?? 72));
        $listingLinks = [];

        foreach ($listingUrls as $listingUrl) {
            $response = $this->request((string) $listingUrl, $source);

            if (! $response->successful()) {
                throw new RuntimeException("{$source->name} respondió HTTP {$response->status()} al consultar un listado de noticias.");
            }

            $listingLinks[] = array_slice(
                $this->articleLinks($source, $response->body(), $prefilterRequiresLocation),
                0,
                $maximumLinks,
            );
        }

        // Interleave the listings so a busy homepage cannot consume the entire
        // article budget before a thematic channel gets its first candidate.
        $links = [];
        $rounds = max(array_map('count', $listingLinks) ?: [0]);

        for ($index = 0; $index < $rounds; $index++) {
            foreach ($listingLinks as $queue) {
                $link = $queue[$index] ?? null;

                if ($link === null || isset($links[$link['url']])) {
                    continue;
                }

                $links[$link['url']] = $link;

                if (count($links) >= $maximumLinks) {
                    break 2;
                }
            }
        }

        $candidates = collect();

        foreach ($links as $link) {
            $response = $this->request($link['url'], $source);

            if (! $response->successful()) {
                continue;
            }

            foreach ($this->articleCandidates($source, $link, $response->body(), $cutoff) as $candidate) {
                $candidates->push($candidate);

                if ($candidates->count() >= $maximumCandidates) {
                    break 2;
                }
            }
        }

        return $candidates;
    }

    /** @return array<int, array{title: string, url: string, published_at: ?string}> */
    private function articleLinks(Source $source, string $html, bool $prefilterRequiresLocation): array
    {
        [$document, $xpath] = $this->document($html);
        $anchors = $xpath->query('//a[@href]');

        if ($anchors === false) {
            return [];
        }

        $links = [];

        foreach ($anchors as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $heading = $xpath->query('.//*[self::h1 or self::h2 or self::h3 or self::h4]', $anchor)?->item(0);
            $parent = $anchor->parentNode;

            if ($heading === null
                && $parent instanceof DOMElement
                && in_array(Str::lower($parent->tagName), ['h1', 'h2', 'h3', 'h4'], true)) {
                $heading = $parent;
            }
            $title = $this->plainText((string) ($heading?->textContent ?: $anchor->textContent));
            $url = $this->absoluteUrl($source, trim($anchor->getAttribute('href')));

            if ($url === null || mb_strlen($title) < 24 || mb_strlen($title) > 260) {
                continue;
            }

            if (! $prefilterRequiresLocation && $heading === null) {
                continue;
            }

            if ($prefilterRequiresLocation) {
                $location = $this->locationExtractor->extract($title);

                // A useful headline may name the event and leave the city to
                // the article. Keep the event filter, then resolve its place
                // from the published article instead of losing it here.
                $category = $location === null
                    ? ($this->classifier->classify($title, '') ?? $this->healthClassifier->classify($title))
                    : $this->category($title, '', $location);

                if ($category === null) {
                    continue;
                }
            }

            $links[$url] = [
                'title' => $title,
                'url' => $url,
                'published_at' => $this->dateNearAnchor($anchor)?->toIso8601String(),
            ];
        }

        unset($document);

        return array_values($links);
    }

    /** @param array{title: string, url: string, published_at: ?string} $link */
    private function articleCandidates(
        Source $source,
        array $link,
        string $html,
        CarbonImmutable $cutoff,
    ): Collection {
        $settings = array_replace_recursive(
            (array) config("alertapy.sources.{$source->slug}", []),
            (array) ($source->configuration_json ?? []),
        );
        [$document, $xpath] = $this->document($html);
        $metadata = [];
        $metaNodes = $xpath->query('//meta[@content]');

        if ($metaNodes !== false) {
            foreach ($metaNodes as $meta) {
                if (! $meta instanceof DOMElement) {
                    continue;
                }

                $key = trim($meta->getAttribute('property') ?: $meta->getAttribute('name'));

                if ($key !== '') {
                    $metadata[$key] = trim($meta->getAttribute('content'));
                }
            }
        }

        $title = $this->plainText((string) ($metadata['og:title'] ?? $link['title']));
        $title = preg_replace('/^(?:La Nación|Última Hora)\s*[\/|—-]\s*/u', '', $title) ?: $title;
        $articleText = $this->articleText($xpath);
        $description = $this->plainText((string) ($metadata['og:description'] ?? $metadata['description'] ?? ''));
        $contextDescription = $description !== '' ? $description : $articleText;
        $content = (bool) ($settings['summary_title_only'] ?? false)
            ? $title
            : ($contextDescription !== '' ? $contextDescription : $title);
        $publishedValue = (string) ($metadata['article:published_time'] ?? $this->jsonLdValue($xpath, 'datePublished') ?? '');
        $publishedAt = $this->date(
            $publishedValue,
            (string) config("alertapy.sources.{$source->slug}.published_timezone", config('app.timezone')),
        ) ?? $this->date((string) ($link['published_at'] ?? ''), (string) config('app.timezone'))
            ?? $this->dateFromText($articleText);

        if ($publishedAt === null || $publishedAt->lt($cutoff) || $publishedAt->isFuture()) {
            return collect();
        }

        $locationEvidence = $description;
        $location = $this->locationExtractor->extract($title, $description);
        if ($location === null && (bool) ($settings['location_search_article_body'] ?? true) && $articleText !== '') {
            $locationEvidence = mb_substr($articleText, 0, 5000);
            $location = $this->locationExtractor->extract($title, $locationEvidence);
        }
        $hasConfiguredCategory = is_string($settings['forced_category_slug'] ?? null);
        $categories = [];

        if ($location !== null && ($settings['category_classifier'] ?? null) === 'health') {
            // Health notices can also describe a flood, road closure, outage,
            // or another territorial event. Keep each supported layer visible.
            // Preserve the existing territorial category as the primary raw-item ID.
            // This lets a new health layer be attached to articles already seen as floods or outages.
            $categories = array_values(array_unique([
                ...$this->classifier->classifyAll($title, $contextDescription),
                ...$this->healthClassifier->classifyAll($title),
            ]));
        } elseif ($location !== null && $hasConfiguredCategory) {
            $configured = $this->configuredCategory($settings, $title, $contextDescription);
            $categories = $configured === null ? [] : [$configured];
        } elseif ($location !== null) {
            // Independent publishers can also surface public-health events;
            // retain any separate territorial category from the same headline.
            $categories = array_values(array_unique([
                ...$this->classifier->classifyAll($title, $contextDescription),
                ...$this->healthClassifier->classifyAll($title),
            ]));

            if ($categories === []) {
                $category = $this->category($title, $contextDescription, $location);
                $categories = $category === null ? [] : [$category];
            }
        }

        if ($location === null || $categories === []) {
            return collect();
        }

        return collect($categories)->map(fn (string $category, int $index): CandidateDTO => new CandidateDTO(
            sourceSlug: $source->slug,
            sourceType: $source->type,
            externalIdentifier: $index === 0
                ? hash('sha256', $link['url'])
                : hash('sha256', $link['url'].'|'.$category),
            title: $title,
            originalUrl: $link['url'],
            content: $content,
            publishedAt: $publishedAt,
            detectedLocation: $location['query'],
            metadata: [
                'category_slug' => $category,
                'category_variant' => $index > 0,
                'location_text' => $location['text'],
                'city' => $location['city'],
                'department' => $location['department'],
                'location_accuracy' => $location['accuracy'],
                'original_title' => $title,
                'homepage_collector' => true,
                'regional_department' => $settings['regional_department'] ?? null,
                'privacy_limited' => (bool) ($settings['summary_title_only'] ?? false),
                'location_evidence' => Str::limit($title.' '.$locationEvidence, 2000, '…'),
                'force_multi_location_department' => (bool) ($settings['force_multi_location_department'] ?? false),
                'dedup_window_hours' => (int) ($settings['dedup_window_hours'] ?? 12),
                'dedup_source_only' => (bool) ($settings['dedup_source_only'] ?? false),
                ...$this->schedules->extract($title."\n".$contextDescription."\n".$articleText, $category, $publishedAt),
            ],
        ));
    }

    /** @param array{text: string, query: string, city: ?string, department: ?string, accuracy: string} $location */
    private function category(string $title, string $description, array $location): ?string
    {
        $category = $this->classifier->classify($title, $description)
            ?? $this->healthClassifier->classify($title);

        if ($category !== null) {
            return $category;
        }

        $evidence = Str::lower(Str::ascii($title.' '.$description));
        $isChaco = in_array($location['department'], ['Boquerón', 'Presidente Hayes', 'Alto Paraguay'], true)
            || preg_match('/\b(chaco|region occidental|medanos del chaco)\b/u', $evidence) === 1;
        $territorial = preg_match('/\b(indigen|ayoreo|comunidad|parque nacional|ambient|extractiv|mineri|hidrocarb|ganader|agric|corredor biocean|obras?|caminos?|rutas?|puente|agua|salud|hospital|aislad|emergencia|inund|sequia|incend|hantavirus|energia)\w*/u', $evidence) === 1;

        return $isChaco && $territorial ? 'territorial_news' : null;
    }

    /** @param array<string, mixed> $settings */
    private function configuredCategory(array $settings, string $title, string $description): ?string
    {
        $category = $settings['forced_category_slug'] ?? null;
        $requiredPattern = $settings['required_event_pattern'] ?? null;
        $excludedPattern = $settings['excluded_event_pattern'] ?? null;

        if (! is_string($category) || ! is_string($requiredPattern) || $category === '' || $requiredPattern === '') {
            return null;
        }

        $evidence = Str::lower(Str::ascii($title.' '.$description));

        if (is_string($excludedPattern) && $excludedPattern !== '' && @preg_match($excludedPattern, $evidence) === 1) {
            return null;
        }

        return @preg_match($requiredPattern, $evidence) === 1 ? $category : null;
    }

    private function request(string $url, Source $source): Response
    {
        $request = Http::accept('text/html, application/xhtml+xml;q=0.9')
            ->withUserAgent((string) config('alertapy.http.user_agent'))
            ->timeout((int) config('alertapy.http.timeout', 15))
            ->retry(2, 300, throw: false);
        $caBundle = config("alertapy.sources.{$source->slug}.tls_ca_bundle");

        if (is_string($caBundle) && is_file($caBundle)) {
            $request = $request->withOptions(['verify' => $caBundle]);
        }

        return $request->get($url);
    }

    private function dynamicListingUrl(string $url): string
    {
        $now = CarbonImmutable::now((string) config('app.timezone'));
        $previous = $now->subMonthNoOverflow();

        return strtr($url, [
            '{year}' => $now->format('Y'),
            '{month}' => $now->format('n'),
            '{month_padded}' => $now->format('m'),
            '{previous_year}' => $previous->format('Y'),
            '{previous_month}' => $previous->format('n'),
            '{previous_month_padded}' => $previous->format('m'),
        ]);
    }

    /** @return array{DOMDocument, DOMXPath} */
    private function document(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return [$document, new DOMXPath($document)];
    }

    private function jsonLdValue(DOMXPath $xpath, string $key): ?string
    {
        $scripts = $xpath->query('//script[@type="application/ld+json"]');

        if ($scripts === false) {
            return null;
        }

        foreach ($scripts as $script) {
            $payload = json_decode(trim((string) $script->textContent), true);
            $value = $this->nestedValue($payload, $key);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function articleText(DOMXPath $xpath): string
    {
        // Footer addresses, navigation and related-news sidebars must never
        // become the location of an article whose event has no known place.
        $paragraphs = $xpath->query('(//main//p | //article//p | //*[@role="main"]//p | //*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]//p)[not(ancestor::footer or ancestor::nav or ancestor::aside)]');

        if ($paragraphs === false) {
            return '';
        }

        $parts = [];

        foreach ($paragraphs as $paragraph) {
            $text = $this->plainText((string) $paragraph->textContent);

            if ($text !== '') {
                $parts[] = $text;
            }

            if (mb_strlen(implode(' ', $parts)) >= 5000) {
                break;
            }
        }

        return Str::limit(implode(' ', $parts), 5000, '…');
    }

    private function dateFromText(string $text): ?CarbonImmutable
    {
        if (preg_match('/(?:fecha|publicado el)?\s*:?\s*(\d{1,2})\s+de\s+(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)\s+de\s+(\d{4})/iu', $text, $match) === 1) {
            $months = [
                'enero' => 1,
                'febrero' => 2,
                'marzo' => 3,
                'abril' => 4,
                'mayo' => 5,
                'junio' => 6,
                'julio' => 7,
                'agosto' => 8,
                'septiembre' => 9,
                'octubre' => 10,
                'noviembre' => 11,
                'diciembre' => 12,
            ];

            return CarbonImmutable::create(
                (int) $match[3],
                $months[Str::lower(Str::ascii($match[2]))],
                (int) $match[1],
                12,
                0,
                0,
                (string) config('app.timezone'),
            );
        }

        if (preg_match('/(?:fecha|publicado el)?\s*:?\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/iu', $text, $match) === 1) {
            return $this->date($match[1], (string) config('app.timezone'));
        }

        if (preg_match('/\b(\d{1,2})\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec),?\s+(\d{4})\b/iu', $text, $match) === 1) {
            return $this->date("{$match[1]} {$match[2]} {$match[3]}", (string) config('app.timezone'));
        }

        return null;
    }

    private function dateNearAnchor(DOMElement $anchor): ?CarbonImmutable
    {
        $node = $anchor;

        for ($depth = 0; $depth < 4 && $node instanceof DOMElement; $depth++) {
            $date = $this->dateFromText($this->plainText((string) $node->textContent));

            if ($date !== null) {
                return $date;
            }

            $parent = $node->parentNode;

            if (! $parent instanceof DOMElement || in_array(Str::lower($parent->tagName), ['body', 'main', 'html'], true)) {
                break;
            }

            $node = $parent;
        }

        return null;
    }

    private function nestedValue(mixed $payload, string $key): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        if (isset($payload[$key]) && is_scalar($payload[$key])) {
            return trim((string) $payload[$key]);
        }

        foreach ($payload as $value) {
            $match = $this->nestedValue($value, $key);

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    private function absoluteUrl(Source $source, string $href): ?string
    {
        if ($href === '' || Str::startsWith($href, ['#', 'javascript:', 'mailto:'])) {
            return null;
        }

        $url = Str::startsWith($href, ['http://', 'https://'])
            ? $href
            : rtrim((string) $source->base_url, '/').'/'.ltrim($href, '/');
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
        $domain = Str::lower($source->domain);

        return $host === $domain || str_ends_with($host, '.'.$domain) ? $url : null;
    }

    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?: $text);
    }

    private function date(string $value, string $assumedTimezone): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $assumedTimezone)
                ->setTimezone((string) config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}

