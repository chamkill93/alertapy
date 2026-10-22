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

class XDiscoveryProvider implements DiscoveryProviderInterface
{
    use FiltersTerritorialDiscovery;

    public function discover(Source $source): Collection
    {
        $settings = (array) config('alertapy.discovery.x');
        $token = trim((string) ($settings['bearer_token'] ?? ''));

        if ($token === '') {
            throw new RuntimeException('Falta configurar X_BEARER_TOKEN.');
        }

        $response = Http::withToken($token)->acceptJson()
            ->timeout((int) config('alertapy.http.timeout', 15))
            ->retry(2, 300, throw: false)
            ->get((string) $settings['endpoint'], [
                'query' => (string) $settings['query'],
                'max_results' => (int) ($settings['max_results'] ?? 50),
                'tweet.fields' => 'created_at,author_id',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("X respondió HTTP {$response->status()}.");
        }

        return collect($response->json('data', []))->map(function (mixed $post): ?DiscoveryItemDTO {
            if (! is_array($post) || ! $this->isRelevant((string) ($post['text'] ?? ''))) {
                return null;
            }

            try {
                $publishedAt = CarbonImmutable::parse((string) ($post['created_at'] ?? ''));
            } catch (Throwable) {
                $publishedAt = null;
            }

            $id = (string) ($post['id'] ?? '');

            return $id === '' ? null : new DiscoveryItemDTO(
                externalIdentifier: $id,
                title: str((string) $post['text'])->squish()->limit(220)->toString(),
                originalUrl: "https://x.com/i/web/status/{$id}",
                content: (string) $post['text'],
                publishedAt: $publishedAt,
                sourceType: SourceType::Social,
                author: isset($post['author_id']) ? (string) $post['author_id'] : null,
                originalDomain: 'x.com',
            );
        })->filter()->values();
    }
}
