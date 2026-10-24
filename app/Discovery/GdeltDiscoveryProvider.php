<?php

namespace App\Discovery;

use App\Contracts\Discovery\DiscoveryProviderInterface;
use App\Discovery\Concerns\FiltersTerritorialDiscovery;
use App\DTOs\DiscoveryItemDTO;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GdeltDiscoveryProvider implements DiscoveryProviderInterface
{
    use FiltersTerritorialDiscovery;

    public function discover(Source $source): Collection
    {
        $settings = (array) config('alertapy.discovery.gdelt');
        $customQuery = trim((string) ($settings['query'] ?? ''));
        $queries = $customQuery !== ''
            ? [$customQuery]
            : array_values(array_filter(array_map('trim', (array) ($settings['queries'] ?? []))));

        if ($queries === []) {
            throw new RuntimeException('GDELT necesita al menos una consulta configurada.');
        }

        $items = collect();
        $successfulQueries = 0;
        $failedQueries = 0;

        foreach ($queries as $index => $query) {
            $response = Http::acceptJson()
                ->withUserAgent((string) config('alertapy.http.user_agent'))
                ->timeout((int) ($settings['timeout'] ?? 12))
                ->get((string) $settings['endpoint'], [
                    'query' => $query,
                    'mode' => 'artlist',
                    'format' => 'json',
                    'maxrecords' => min(250, max(1, (int) ($settings['max_results'] ?? 25))),
                    'timespan' => (string) ($settings['timespan'] ?? '24h'),
                    'sort' => 'datedesc',
                ]);

            if (! $response->successful()) {
                $failedQueries++;
                Log::warning('Falló un grupo de búsqueda de GDELT.', [
                    'source' => $source->slug,
                    'group' => $index + 1,
                    'status' => $response->status(),
                ]);

                continue;
            }

            $successfulQueries++;
            $items = $items->merge(
                collect($response->json('articles', []))
                    ->map(fn (mixed $article): ?DiscoveryItemDTO => $this->mapArticle($article))
                    ->filter(),
            );
        }

        if ($successfulQueries === 0) {
            throw new RuntimeException("Fallaron los {$failedQueries} grupos de búsqueda de GDELT.");
        }

        return $items->unique('originalUrl')->values();
    }

    private function mapArticle(mixed $article): ?DiscoveryItemDTO
    {
        if (! is_array($article)) {
            return null;
        }

        $url = trim((string) ($article['url'] ?? ''));
        $title = trim((string) ($article['title'] ?? ''));
        $domain = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($title === '' || ! $this->validPublicUrl($url) || str_contains($domain, 'gdeltproject.org') || ! $this->isRelevant($title)) {
            return null;
        }

        try {
            $publishedAt = isset($article['seendate'])
                ? CarbonImmutable::createFromFormat('Ymd\\THis\\Z', (string) $article['seendate'], 'UTC')
                : null;
        } catch (Throwable) {
            $publishedAt = null;
        }

        return new DiscoveryItemDTO(
            externalIdentifier: hash('sha256', $url),
            title: $title,
            originalUrl: $url,
            content: $title,
            publishedAt: $publishedAt?->setTimezone((string) config('app.timezone')),
            sourceType: SourceType::Discovery,
            originalDomain: $domain,
        );
    }
}
