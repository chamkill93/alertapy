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
use DOMNode;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AbcColorCollector implements CollectorInterface
{
    public function __construct(
        private readonly ParaguayLocationExtractor $locationExtractor,
        private readonly PressIncidentClassifier $classifier,
    ) {}

    public function collect(Source $source): Collection
    {
        $lookbackDays = (int) config('alertapy.sources.abc.lookback_days', 0);

        if ($lookbackDays > 0) {
            return $this->collectArchive($source, $lookbackDays);
        }

        $response = Http::accept('application/rss+xml, application/xml;q=0.9, text/xml;q=0.8')
            ->withUserAgent((string) config('alertapy.http.user_agent'))
            ->timeout((int) config('alertapy.http.timeout', 15))
            ->retry(2, 300)
            ->get((string) config('alertapy.sources.abc.feed_url'));

        if (! $response->successful()) {
            throw new RuntimeException("ABC Color respondió HTTP {$response->status()}.");
        }

        $candidates = $this->parse($source, $response->body());

        if ((bool) config('alertapy.sources.abc.recent_archive_enabled', true)) {
            // The short RSS can omit recent operational reports. One public
            // archive page per configured section keeps the scheduled run bounded.
            $candidates = $this->collectArchive($source, 0, true)
                ->concat($candidates)
                ->unique('externalIdentifier')
                ->sortByDesc(fn (CandidateDTO $candidate) => $candidate->publishedAt?->getTimestamp() ?? 0)
                ->take(max(1, (int) config('alertapy.sources.abc.max_candidates', 30)))
                ->values();
        }

        return $candidates;
    }

    /** @return Collection<int, CandidateDTO> */
    private function collectArchive(Source $source, int $lookbackDays, bool $incremental = false): Collection
    {
        $cutoff = $incremental
            ? CarbonImmutable::now((string) config('app.timezone'))->subHours((int) config('alertapy.sources.abc.max_age_hours', 48))
            : CarbonImmutable::now((string) config('app.timezone'))->subDays($lookbackDays);
        $pageSize = max(1, min(100, (int) config('alertapy.sources.abc.archive_page_size', 100)));
        $maximumPages = $incremental ? 1 : max(1, (int) config('alertapy.sources.abc.archive_max_pages', 20));
        $maximumCandidates = max(1, (int) config('alertapy.sources.abc.archive_max_candidates', 500));
        $sections = (array) config('alertapy.sources.abc.archive_sections', []);
        $candidates = collect();
        $seen = [];

        foreach ($sections as $sectionId => $uri) {
            for ($page = 0; $page < $maximumPages; $page++) {
                $articles = $this->archivePage((string) $sectionId, (string) $uri, $pageSize, $page * $pageSize);

                if ($articles === []) {
                    break;
                }

                $reachedCutoff = false;

                foreach ($articles as $article) {
                    if (! is_array($article)) {
                        continue;
                    }

                    $publishedAt = $this->date((string) ($article['display_date'] ?? $article['publish_date'] ?? ''));

                    if ($publishedAt === null || $publishedAt->lt($cutoff) || $publishedAt->isFuture()) {
                        $reachedCutoff = $publishedAt !== null && $publishedAt->lt($cutoff);

                        continue;
                    }

                    $candidate = $this->candidateFromArchiveArticle($source, $article, $publishedAt, ! $incremental);

                    if ($candidate === null || isset($seen[$candidate->externalIdentifier])) {
                        continue;
                    }

                    $seen[$candidate->externalIdentifier] = true;
                    $candidates->push($candidate);

                    if ($candidates->count() >= $maximumCandidates) {
                        return $candidates;
                    }
                }

                if ($reachedCutoff || count($articles) < $pageSize) {
                    break;
                }
            }
        }

        return $candidates;
    }

    /** @return array<int, array<string, mixed>> */
    private function archivePage(string $sectionId, string $uri, int $limit, int $offset): array
    {
        $query = json_encode([
            'arc-site' => 'abccolor',
            'id' => $sectionId,
            'limit' => (string) $limit,
            'offset' => (string) $offset,
            'sort' => 'display_date:desc',
            'uri' => $uri,
        ], JSON_THROW_ON_ERROR);
        $response = Http::acceptJson()
            ->withUserAgent((string) config('alertapy.http.user_agent'))
            ->timeout(max(30, (int) config('alertapy.http.timeout', 15)))
            ->retry(2, 500)
            ->get((string) config('alertapy.sources.abc.archive_url'), [
                'query' => $query,
                '_website' => 'abccolor',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("El archivo de ABC Color respondió HTTP {$response->status()}.");
        }

        $articles = $response->json('content_elements');

        if (! is_array($articles)) {
            throw new RuntimeException('El archivo de ABC Color no contiene una lista válida de artículos.');
        }

        return $articles;
    }

    /** @param array<string, mixed> $article */
    private function candidateFromArchiveArticle(
        Source $source,
        array $article,
        CarbonImmutable $publishedAt,
        bool $backfill = true,
    ): ?CandidateDTO {
        $title = trim((string) data_get($article, 'headlines.basic', ''));
        $canonicalUrl = trim((string) ($article['canonical_url'] ?? ''));

        if ($title === '' || $canonicalUrl === '') {
            return null;
        }

        $url = Str::startsWith($canonicalUrl, ['http://', 'https://'])
            ? $canonicalUrl
            : rtrim((string) $source->base_url, '/').'/'.ltrim($canonicalUrl, '/');
        $contentParts = [(string) data_get($article, 'description.basic', '')];

        foreach ((array) ($article['content_elements'] ?? []) as $element) {
            if (! is_array($element)) {
                continue;
            }

            $contentParts[] = (string) ($element['content'] ?? $element['text'] ?? '');
        }

        $content = $this->plainText(implode(' ', $contentParts));
        $category = $this->classifier->classify($title, $content);
        $location = $this->locationExtractor->extract($title, mb_substr($content, 0, 5000));

        if ($category === null || $location === null) {
            return null;
        }

        return new CandidateDTO(
            sourceSlug: $source->slug,
            sourceType: $source->type,
            externalIdentifier: hash('sha256', $url),
            title: $title,
            originalUrl: $url,
            content: $content,
            publishedAt: $publishedAt,
            detectedLocation: $location['query'],
            metadata: [
                'category_slug' => $category,
                'location_text' => $location['text'],
                'city' => $location['city'],
                'department' => $location['department'],
                'location_accuracy' => $location['accuracy'],
                'original_title' => $title,
                'archive_backfill' => $backfill,
            ],
        );
    }

    /** @return Collection<int, CandidateDTO> */
    public function parse(Source $source, string $xml): Collection
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('El RSS de ABC Color no contiene XML válido.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
        $items = $xpath->query('/rss/channel/item');

        if ($items === false) {
            return collect();
        }

        $candidates = collect();
        $maximumAge = now()->subHours((int) config('alertapy.sources.abc.max_age_hours', 48));
        $maximumCandidates = (int) config('alertapy.sources.abc.max_candidates', 30);

        foreach ($items as $item) {
            if (! $item instanceof DOMElement || $candidates->count() >= $maximumCandidates) {
                break;
            }

            $title = $this->nodeValue($xpath, './title', $item);
            $url = $this->nodeValue($xpath, './link', $item);
            $guid = $this->nodeValue($xpath, './guid', $item) ?: $url;
            $rawContent = $this->nodeValue($xpath, './content:encoded', $item)
                ?: $this->nodeValue($xpath, './description', $item);
            $content = $this->plainText($rawContent);
            $publishedAt = $this->date($this->nodeValue($xpath, './pubDate', $item));

            if ($title === '' || $url === '' || $publishedAt === null || $publishedAt->lt($maximumAge) || $publishedAt->isFuture()) {
                continue;
            }

            $category = $this->classifier->classify($title, $content);
            $location = $this->locationExtractor->extract($title, mb_substr($content, 0, 3500));

            if ($category === null || $location === null) {
                continue;
            }

            $candidates->push(new CandidateDTO(
                sourceSlug: $source->slug,
                sourceType: $source->type,
                externalIdentifier: hash('sha256', $url),
                title: $title,
                originalUrl: $url,
                content: $content,
                publishedAt: $publishedAt,
                detectedLocation: $location['query'],
                metadata: [
                    'category_slug' => $category,
                    'location_text' => $location['text'],
                    'city' => $location['city'],
                    'department' => $location['department'],
                    'location_accuracy' => $location['accuracy'],
                    'original_title' => $title,
                ],
            ));
        }

        return $candidates;
    }

    private function nodeValue(DOMXPath $xpath, string $expression, DOMNode $context): string
    {
        $node = $xpath->query($expression, $context)?->item(0);

        return trim((string) $node?->nodeValue);
    }

    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?: $text);
    }

    private function date(string $value): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
