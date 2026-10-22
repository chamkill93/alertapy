<?php

namespace App\Collectors;

use App\Contracts\Collectors\CollectorInterface;
use App\DTOs\CandidateDTO;
use App\Models\Source;
use App\Services\ParaguayLocationExtractor;
use App\Services\PressIncidentClassifier;
use App\Services\UtilityScheduleExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Public publisher searches, independent of the providers' official websites. */
class UtilityNewsCollector implements CollectorInterface
{
    public function __construct(
        private readonly ParaguayLocationExtractor $locations,
        private readonly PressIncidentClassifier $classifier,
        private readonly UtilityScheduleExtractor $schedules,
    ) {}

    /** @return Collection<int, CandidateDTO> */
    public function collect(Source $source): Collection
    {
        $settings = array_replace(
            (array) config("alertapy.sources.{$source->slug}", []),
            (array) ($source->configuration_json ?? []),
        );
        $apiUrl = (string) ($settings['api_url'] ?? '');
        if (! $this->belongsToPublisher($apiUrl, $source)) {
            throw new RuntimeException("{$source->name}: falta una API pública del dominio de la fuente.");
        }

        $timezone = (string) ($settings['published_timezone'] ?? config('app.timezone'));
        $days = max(0, min(30, (int) ($settings['lookback_days'] ?? 0)));
        $cutoff = CarbonImmutable::now($timezone)->subHours($days > 0 ? $days * 24 : (int) ($settings['max_age_hours'] ?? 168));
        $terms = array_slice(array_values(array_unique(array_filter(array_map('trim',
            (array) ($settings['search_terms'] ?? ['agua', 'energía', 'eléctrica', 'luz', 'apagón']),
        )))), 0, 6);
        if ($terms === []) {
            throw new RuntimeException("{$source->name}: no hay búsquedas de servicios configuradas.");
        }
        $pageSize = max(1, min(100, (int) ($settings['page_size'] ?? 25)));
        $maximumPages = max(1, min(3, (int) ($settings['max_pages'] ?? 1)));
        $maximumCandidates = max(1, min(100, (int) ($settings['max_candidates'] ?? 30)));
        $candidates = collect();
        $seenUrls = [];

        foreach ($terms as $term) {
            for ($page = 1; $page <= $maximumPages; $page++) {
                $response = Http::acceptJson()
                    ->withUserAgent((string) config('alertapy.http.user_agent'))
                    ->connectTimeout(10)
                    ->timeout(min(15, max(1, (int) config('alertapy.http.timeout', 15))))
                    ->retry(2, 300, throw: false)
                    ->get($apiUrl, [
                        'search' => $term,
                        // WP compares `after` to the publisher's local post_date.
                        'after' => $cutoff->format('Y-m-d\TH:i:s'),
                        'orderby' => 'date', 'order' => 'desc',
                        'page' => $page, 'per_page' => $pageSize,
                        '_fields' => 'id,date,date_gmt,link,title,excerpt,content',
                    ]);

                if (! $response->successful()) {
                    throw new RuntimeException("{$source->name}: búsqueda de servicios respondió HTTP {$response->status()}.");
                }
                $posts = $response->json();
                if (! is_array(json_decode($response->body())) || ! is_array($posts) || ! array_is_list($posts)) {
                    throw new RuntimeException("{$source->name}: la API no devolvió una lista de noticias válida.");
                }

                foreach ($posts as $post) {
                    if (! is_array($post)) {
                        throw new RuntimeException("{$source->name}: formato de noticia no válido.");
                    }
                    $candidate = $this->candidate($source, $post, $cutoff, $timezone);
                    if ($candidate !== null && ! isset($seenUrls[$candidate->originalUrl])) {
                        $candidates->put($candidate->externalIdentifier, $candidate);
                        $seenUrls[$candidate->originalUrl] = true;
                    }
                }

                $totalPages = (int) $response->header('X-WP-TotalPages');
                if (count($posts) < $pageSize || ($totalPages > 0 && $page >= $totalPages)) {
                    break;
                }
            }
        }

        // All topics get a chance before applying the global candidate limit.
        return $candidates->sortByDesc(fn (CandidateDTO $candidate) => $candidate->publishedAt->timestamp)
            ->take($maximumCandidates)->values();
    }

    /** @param array<string, mixed> $post */
    private function candidate(Source $source, array $post, CarbonImmutable $cutoff, string $timezone): ?CandidateDTO
    {
        $id = $post['id'] ?? null;
        $url = trim((string) ($post['link'] ?? ''));
        $title = $this->plainText((string) data_get($post, 'title.rendered', ''));
        $excerpt = $this->plainText((string) data_get($post, 'excerpt.rendered', ''));
        $body = $this->plainText((string) data_get($post, 'content.rendered', ''));
        $gmt = trim((string) ($post['date_gmt'] ?? ''));
        $publishedAt = $this->date($gmt !== '' ? $gmt : (string) ($post['date'] ?? ''), $gmt !== '' ? 'UTC' : $timezone);
        if (! is_numeric($id) || (int) $id < 1 || ! $this->belongsToPublisher($url, $source)
            || $title === '' || $publishedAt === null || $publishedAt->lt($cutoff) || $publishedAt->isFuture()) {
            return null;
        }
        $category = $this->classifier->classify($title, $excerpt);
        if (! in_array($category, ['electricity', 'water'], true)) {
            return null;
        }
        $location = $this->locations->extract($title, $excerpt, mb_substr($body, 0, 5000));
        if ($location === null) {
            return null;
        }

        return new CandidateDTO(
            sourceSlug: $source->slug,
            sourceType: $source->type,
            externalIdentifier: $source->slug.'-wp-'.(int) $id,
            title: $title,
            originalUrl: $url,
            content: $excerpt !== '' ? $excerpt : mb_substr($body ?: $title, 0, 1200),
            publishedAt: $publishedAt,
            detectedLocation: $location['query'],
            metadata: [
                'category_slug' => $category,
                'location_text' => $location['text'],
                'city' => $location['city'],
                'department' => $location['department'],
                'location_accuracy' => $location['accuracy'],
                'location_evidence' => mb_substr($excerpt.' '.$body, 0, 2000),
                'original_title' => $title,
                'utility_news_collector' => true,
                ...$this->schedules->extract($title."\n".$excerpt."\n".$body, $category, $publishedAt),
            ],
        );
    }

    private function belongsToPublisher(string $url, Source $source): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $domain = strtolower((string) $source->domain);

        return in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
            && $domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain));
    }

    private function plainText(string $html): string
    {
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html) ?? $html;
        $html = preg_replace('~</?(?:p|div|br|li|h[1-6]|section|article)\b[^>]*>~i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function date(string $value, string $timezone): ?CarbonImmutable
    {
        // Reject missing, relative and impossible dates instead of rolling them forward.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z?$/D', trim($value), $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) {
            return null;
        }

        return CarbonImmutable::parse($value, $timezone)->setTimezone((string) config('app.timezone'));
    }
}
