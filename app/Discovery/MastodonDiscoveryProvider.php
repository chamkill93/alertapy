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

class MastodonDiscoveryProvider implements DiscoveryProviderInterface
{
    use FiltersTerritorialDiscovery;

    public function discover(Source $source): Collection
    {
        $settings = (array) config('alertapy.discovery.mastodon');
        $instances = array_values(array_filter((array) ($settings['instances'] ?? [])));
        $hashtags = array_values(array_filter((array) ($settings['hashtags'] ?? [])));

        if ($instances === [] || $hashtags === []) {
            throw new RuntimeException('MASTODON_INSTANCES y MASTODON_HASHTAGS deben estar configurados.');
        }

        $items = collect();

        foreach ($instances as $instance) {
            $base = 'https://'.strtolower(trim((string) $instance));

            foreach ($hashtags as $hashtag) {
                $response = Http::acceptJson()->timeout((int) config('alertapy.http.timeout', 15))
                    ->retry(1, 250, throw: false)
                    ->get($base.'/api/v1/timelines/tag/'.rawurlencode((string) $hashtag), ['limit' => 20, 'local' => true]);

                if (! $response->successful()) {
                    continue;
                }

                foreach ((array) $response->json() as $status) {
                    if (! is_array($status)) {
                        continue;
                    }

                    $text = trim(strip_tags((string) ($status['content'] ?? '')));
                    $url = (string) ($status['url'] ?? '');

                    if (! $this->isRelevant($text) || ! $this->validPublicUrl($url)) {
                        continue;
                    }

                    try {
                        $publishedAt = CarbonImmutable::parse((string) ($status['created_at'] ?? ''));
                    } catch (Throwable) {
                        $publishedAt = null;
                    }

                    $items->push(new DiscoveryItemDTO(
                        externalIdentifier: (string) ($status['uri'] ?? $url),
                        title: str($text)->squish()->limit(220)->toString(),
                        originalUrl: $url,
                        content: $text,
                        publishedAt: $publishedAt,
                        sourceType: SourceType::Social,
                        author: (string) data_get($status, 'account.acct'),
                        originalDomain: strtolower((string) parse_url($url, PHP_URL_HOST)),
                    ));
                }
            }
        }

        return $items->unique('externalIdentifier')->values();
    }
}
