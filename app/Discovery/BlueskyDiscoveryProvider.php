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

class BlueskyDiscoveryProvider implements DiscoveryProviderInterface
{
    use FiltersTerritorialDiscovery;

    public function discover(Source $source): Collection
    {
        $settings = (array) config('alertapy.discovery.bluesky');
        $response = Http::acceptJson()
            ->withUserAgent((string) config('alertapy.http.user_agent'))
            ->timeout((int) config('alertapy.http.timeout', 15))
            ->retry(2, 300, throw: false)
            ->get((string) $settings['endpoint'], [
                'q' => (string) $settings['query'],
                'limit' => (int) ($settings['max_results'] ?? 50),
                'sort' => 'latest',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Bluesky respondió HTTP {$response->status()}.");
        }

        return collect($response->json('posts', []))->map(function (mixed $post): ?DiscoveryItemDTO {
            $text = is_array($post) ? trim((string) data_get($post, 'record.text')) : '';
            $uri = is_array($post) ? (string) ($post['uri'] ?? '') : '';
            $handle = is_array($post) ? (string) data_get($post, 'author.handle') : '';
            $parts = explode('/', $uri);
            $recordKey = end($parts);

            if ($text === '' || $handle === '' || ! is_string($recordKey) || $recordKey === '' || ! $this->isRelevant($text)) {
                return null;
            }

            try {
                $publishedAt = CarbonImmutable::parse((string) (data_get($post, 'record.createdAt') ?? $post['indexedAt'] ?? ''));
            } catch (Throwable) {
                $publishedAt = null;
            }

            return new DiscoveryItemDTO(
                externalIdentifier: $uri,
                title: str($text)->squish()->limit(220)->toString(),
                originalUrl: "https://bsky.app/profile/{$handle}/post/{$recordKey}",
                content: $text,
                publishedAt: $publishedAt,
                sourceType: SourceType::Social,
                author: $handle,
                originalDomain: 'bsky.app',
            );
        })->filter()->values();
    }
}
