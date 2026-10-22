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

class YouTubeDiscoveryProvider implements DiscoveryProviderInterface
{
    use FiltersTerritorialDiscovery;

    public function discover(Source $source): Collection
    {
        $settings = (array) config('alertapy.discovery.youtube');
        $key = trim((string) ($settings['api_key'] ?? ''));
        $channels = array_values(array_filter((array) ($settings['channel_ids'] ?? [])));

        if ($key === '' || $channels === []) {
            throw new RuntimeException('YOUTUBE_API_KEY y YOUTUBE_CHANNEL_IDS deben estar configurados.');
        }

        $items = collect();
        $publishedAfter = now()->subHours((int) ($settings['lookback_hours'] ?? 24))->utc()->toIso8601ZuluString();

        foreach ($channels as $channelId) {
            $response = Http::acceptJson()->timeout((int) config('alertapy.http.timeout', 15))
                ->retry(1, 250, throw: false)
                ->get((string) $settings['endpoint'], [
                    'key' => $key,
                    'part' => 'snippet',
                    'channelId' => $channelId,
                    'publishedAfter' => $publishedAfter,
                    'order' => 'date',
                    'type' => 'video',
                    'maxResults' => (int) ($settings['max_results_per_channel'] ?? 10),
                    'q' => (string) $settings['query'],
                ]);

            if (! $response->successful()) {
                continue;
            }

            foreach ((array) $response->json('items', []) as $video) {
                if (! is_array($video)) {
                    continue;
                }

                $id = (string) data_get($video, 'id.videoId');
                $title = html_entity_decode((string) data_get($video, 'snippet.title'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $description = html_entity_decode((string) data_get($video, 'snippet.description'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if ($id === '' || ! $this->isRelevant($title.' '.$description)) {
                    continue;
                }

                try {
                    $publishedAt = CarbonImmutable::parse((string) data_get($video, 'snippet.publishedAt'));
                } catch (Throwable) {
                    $publishedAt = null;
                }

                $items->push(new DiscoveryItemDTO(
                    externalIdentifier: $id,
                    title: $title,
                    originalUrl: "https://www.youtube.com/watch?v={$id}",
                    content: trim($title.' '.$description),
                    publishedAt: $publishedAt,
                    sourceType: SourceType::Discovery,
                    author: (string) data_get($video, 'snippet.channelTitle'),
                    originalDomain: 'youtube.com',
                ));
            }
        }

        return $items->unique('externalIdentifier')->values();
    }
}
