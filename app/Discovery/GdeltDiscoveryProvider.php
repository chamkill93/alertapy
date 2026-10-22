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
use RuntimeException;
use Throwable;

class GdeltDiscoveryProvider implements DiscoveryProviderInterface
{
    use FiltersTerritorialDiscovery;

    public function discover(Source $source): Collection
    {
        $settings = (array) config('alertapy.discovery.gdelt');
        $response = Http::acceptJson()
            ->withUserAgent((string) config('alertapy.http.user_agent'))
            ->timeout((int) ($settings['timeout'] ?? 30))
            ->get((string) $settings['endpoint'], [
                'query' => (string) $settings['query'],
                'mode' => 'artlist',
                'format' => 'json',
                'maxrecords' => (int) ($settings['max_results'] ?? 50),
                'timespan' => (string) ($settings['timespan'] ?? '24h'),
                'sort' => 'datedesc',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("GDELT respondió HTTP {$response->status()}.");
        }

        return collect($response->json('articles', []))->map(function (mixed $article): ?DiscoveryItemDTO {
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
                    ? CarbonImmutable::createFromFormat('Ymd\THis\Z', (string) $article['seendate'], 'UTC')
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
        })->filter()->unique('originalUrl')->values();
    }
}
