<?php

namespace App\Collectors;

use App\Contracts\Collectors\CollectorInterface;
use App\DTOs\CandidateDTO;
use App\Models\Source;
use App\Services\ParaguayLocationExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SenepaCollector implements CollectorInterface
{
    public function __construct(private readonly ParaguayLocationExtractor $locationExtractor) {}

    /** @return Collection<int, CandidateDTO> */
    public function collect(Source $source): Collection
    {
        $timezone = (string) config('app.timezone');
        $lookbackDays = (int) config('alertapy.sources.senepa.lookback_days', 0);
        $cutoff = $lookbackDays > 0
            ? CarbonImmutable::now($timezone)->startOfDay()->subDays($lookbackDays)
            : CarbonImmutable::now($timezone)->subHours((int) config('alertapy.sources.senepa.max_age_hours', 168));
        $pageSize = max(1, min(100, (int) config('alertapy.sources.senepa.page_size', 50)));
        $maximumPages = max(1, (int) config('alertapy.sources.senepa.max_pages', 3));
        $maximumCandidates = max(1, (int) config('alertapy.sources.senepa.max_candidates', 50));
        $candidates = collect();

        for ($page = 1; $page <= $maximumPages; $page++) {
            $response = Http::acceptJson()
                ->withUserAgent((string) config('alertapy.http.user_agent'))
                ->timeout((int) config('alertapy.http.timeout', 15))
                ->retry(2, 300)
                ->get((string) config('alertapy.sources.senepa.api_url'), [
                    'after' => $cutoff->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                    'orderby' => 'date',
                    'order' => 'desc',
                    'page' => $page,
                    'per_page' => $pageSize,
                    '_fields' => 'id,date,modified,link,slug,title,excerpt,content,categories',
                ]);

            if (! $response->successful()) {
                throw new RuntimeException("La API oficial de SENEPA respondió HTTP {$response->status()}.");
            }

            $posts = $response->json();
            if (! is_array($posts)) {
                throw new RuntimeException('La API oficial de SENEPA no devolvió una lista válida de publicaciones.');
            }

            foreach ($posts as $post) {
                if (! is_array($post)) continue;
                $candidate = $this->candidate($source, $post, $cutoff);
                if ($candidate !== null) $candidates->push($candidate);
                if ($candidates->count() >= $maximumCandidates) return $candidates;
            }

            $totalPages = max(1, (int) $response->header('X-WP-TotalPages'));
            if ($posts === [] || count($posts) < $pageSize || $page >= $totalPages) break;
        }

        return $candidates;
    }

    /** @param array<string, mixed> $post */
    private function candidate(Source $source, array $post, CarbonImmutable $cutoff): ?CandidateDTO
    {
        $id = $post['id'] ?? null;
        $url = trim((string) ($post['link'] ?? ''));
        $title = $this->plainText((string) data_get($post, 'title.rendered', ''));
        $excerpt = $this->plainText((string) data_get($post, 'excerpt.rendered', ''));
        $content = $this->plainText((string) data_get($post, 'content.rendered', ''));
        $publishedAt = $this->date((string) ($post['date'] ?? ''));
        if ($id === null || $url === '' || $title === '' || $publishedAt === null || $publishedAt->lt($cutoff)) return null;

        $evidence = Str::lower(Str::ascii($title.' '.$excerpt.' '.mb_substr($content, 0, 5000)));
        $required = (string) config('alertapy.sources.senepa.required_event_pattern');
        $excluded = (string) config('alertapy.sources.senepa.excluded_event_pattern');
        if ($required === '' || @preg_match($required, $evidence) !== 1) return null;
        if ($excluded !== '' && @preg_match($excluded, $evidence) === 1) return null;

        $location = $this->locationExtractor->extract($title, $excerpt, mb_substr($content, 0, 5000));
        if ($location === null) return null;

        return new CandidateDTO(
            sourceSlug: $source->slug,
            sourceType: $source->type,
            externalIdentifier: 'senepa-'.$id,
            title: $title,
            originalUrl: $url,
            content: trim($excerpt.' '.$content) ?: $title,
            publishedAt: $publishedAt,
            detectedLocation: $location['query'],
            metadata: [
                'category_slug' => 'health_vector',
                'location_text' => $location['text'],
                'city' => $location['city'],
                'department' => $location['department'],
                'location_accuracy' => $location['accuracy'],
                'original_title' => $title,
                'official_api' => true,
            ],
        );
    }

    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?: $text);
    }

    private function date(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value, (string) config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
