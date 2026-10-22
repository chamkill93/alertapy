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

class EssapCollector implements CollectorInterface
{
    public function __construct(private readonly ParaguayLocationExtractor $locationExtractor) {}

    /** @return Collection<int, CandidateDTO> */
    public function collect(Source $source): Collection
    {
        $timezone = (string) config('app.timezone');
        $lookbackDays = (int) config('alertapy.sources.essap.lookback_days', 0);
        $cutoff = $lookbackDays > 0
            ? CarbonImmutable::now($timezone)->subDays($lookbackDays)
            : CarbonImmutable::now($timezone)->subHours((int) config('alertapy.sources.essap.max_age_hours', 48));
        $pageSize = max(1, min(100, (int) config('alertapy.sources.essap.page_size', 100)));
        $maximumPages = max(1, (int) config('alertapy.sources.essap.max_pages', 5));
        $maximumCandidates = max(1, (int) config('alertapy.sources.essap.max_candidates', 250));
        $categoryIds = array_values(array_filter(array_map(
            'intval',
            (array) config('alertapy.sources.essap.category_ids', [6, 7, 34, 35]),
        )));
        $candidates = collect();

        for ($page = 1; $page <= $maximumPages; $page++) {
            $query = [
                'after' => $cutoff->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                'orderby' => 'date',
                'order' => 'desc',
                'page' => $page,
                'per_page' => $pageSize,
                '_fields' => 'id,date,modified,link,slug,title,excerpt,content,categories',
            ];

            if ($categoryIds !== []) {
                $query['categories'] = implode(',', $categoryIds);
            }

            $response = Http::acceptJson()
                ->withUserAgent((string) config('alertapy.http.user_agent'))
                ->timeout((int) config('alertapy.http.timeout', 15))
                ->retry(2, 300)
                ->get((string) config('alertapy.sources.essap.api_url'), $query);

            if (! $response->successful()) {
                throw new RuntimeException("La API oficial de ESSAP respondió HTTP {$response->status()}.");
            }

            $posts = $response->json();

            if (! is_array($posts)) {
                throw new RuntimeException('La API oficial de ESSAP no devolvió una lista válida de publicaciones.');
            }

            foreach ($posts as $post) {
                if (! is_array($post)) {
                    continue;
                }

                $candidate = $this->candidate($source, $post, $cutoff);

                if ($candidate !== null) {
                    $candidates->push($candidate);
                }

                if ($candidates->count() >= $maximumCandidates) {
                    return $candidates;
                }
            }

            $totalPages = max(1, (int) $response->header('X-WP-TotalPages'));

            if ($posts === [] || count($posts) < $pageSize || $page >= $totalPages) {
                break;
            }
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

        if ($id === null || $url === '' || $title === '' || $publishedAt === null || $publishedAt->lt($cutoff) || $publishedAt->isFuture()) {
            return null;
        }

        $classificationText = trim($title.' '.mb_substr($excerpt, 0, 900));

        if (! $this->isWaterIncident($classificationText)) {
            return null;
        }

        $location = $this->locationExtractor->extract($title, $excerpt, mb_substr($content, 0, 5000));

        if ($location === null) {
            return null;
        }

        $evidence = trim($excerpt.' '.$content);

        return new CandidateDTO(
            sourceSlug: $source->slug,
            sourceType: $source->type,
            externalIdentifier: 'essap-'.$id,
            title: $title,
            originalUrl: $url,
            content: $evidence !== '' ? $evidence : $title,
            publishedAt: $publishedAt,
            detectedLocation: $location['query'],
            metadata: [
                'category_slug' => 'water',
                'location_text' => $location['text'],
                'city' => $location['city'],
                'department' => $location['department'],
                'location_accuracy' => $location['accuracy'],
                'original_title' => $title,
                'official_api' => true,
            ],
        );
    }

    private function isWaterIncident(string $value): bool
    {
        $value = Str::lower(Str::ascii($value));
        $waterContext = preg_match('/\b(falta de agua|sin agua|agua potable|servicio de agua|suministro de agua|red de (agua|distribucion)|sistema de agua|acueducto|tuberias?|canerias?)\b/u', $value) === 1;
        $operationalEvent = preg_match('/\b(falta|sin agua|corte|interrupc|suspens|baja presion|restablec|normaliz|rotura|averia|reparac|trabajos?|obras?|mantenim|bombeo|tuberias?|canerias?|aductora|instalacion de (una )?(bomba|pozo))\w*/u', $value) === 1;

        return $waterContext && $operationalEvent;
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
            return CarbonImmutable::parse($value, (string) config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
