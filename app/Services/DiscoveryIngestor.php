<?php

namespace App\Services;

use App\Contracts\Discovery\DiscoveryProviderInterface;
use App\DTOs\DiscoveryItemDTO;
use App\Models\ContentCandidate;
use App\Models\RawItem;
use App\Models\Source;
use App\Models\SourceRun;
use Illuminate\Support\Str;
use Throwable;

class DiscoveryIngestor
{
    public function __construct(
        private readonly ParaguayLocationExtractor $locations,
        private readonly GeocodingService $geocoding,
    ) {}

    /** @return array{discovered: int, queued: int, waiting_location: int, unchanged: int, ignored_date: int} */
    public function ingest(Source $source, DiscoveryProviderInterface $provider): array
    {
        $startedAt = now();
        $run = SourceRun::query()->create(['source_id' => $source->id, 'started_at' => $startedAt, 'status' => 'running']);
        $source->forceFill(['last_run_at' => $startedAt])->save();
        $stats = ['discovered' => 0, 'queued' => 0, 'waiting_location' => 0, 'unchanged' => 0, 'ignored_date' => 0];

        try {
            $items = $provider->discover($source);
            $stats['discovered'] = $items->count();

            foreach ($items as $item) {
                $stats[$this->store($source, $item)]++;
            }

            $finishedAt = now();
            $run->forceFill([
                'finished_at' => $finishedAt,
                'status' => 'success',
                'discovered' => $stats['discovered'],
                'created_candidates' => $stats['queued'] + $stats['waiting_location'],
                'duration_ms' => $startedAt->diffInMilliseconds($finishedAt),
            ])->save();
            $source->forceFill([
                'last_success_at' => $finishedAt,
                'last_change_at' => ($stats['queued'] + $stats['waiting_location']) > 0 ? $finishedAt : $source->last_change_at,
                'last_error' => null,
                'consecutive_errors' => 0,
            ])->save();

            return $stats;
        } catch (Throwable $exception) {
            $finishedAt = now();
            $message = Str::limit($exception->getMessage(), 1900, '…');
            $run->forceFill(['finished_at' => $finishedAt, 'status' => 'error', 'error' => $message, 'duration_ms' => $startedAt->diffInMilliseconds($finishedAt)])->save();
            $source->forceFill(['last_error' => $message, 'consecutive_errors' => min(65535, $source->consecutive_errors + 1)])->save();

            throw $exception;
        }
    }

    private function store(Source $source, DiscoveryItemDTO $item): string
    {
        if ($item->publishedAt === null || $item->publishedAt->lt(app(DataRetentionService::class)->cutoff()) || $item->publishedAt->isFuture()) {
            return 'ignored_date';
        }

        $contentHash = hash('sha256', $item->title."\n".$item->content."\n".$item->originalUrl);
        $raw = RawItem::query()->where('source_id', $source->id)
            ->where(fn ($query) => $query->where('external_identifier', $item->externalIdentifier)->orWhere('content_hash', $contentHash))
            ->first();

        if ($raw !== null && $raw->content_hash === $contentHash && ContentCandidate::query()->where('raw_item_id', $raw->id)->exists()) {
            return 'unchanged';
        }

        $raw ??= new RawItem(['source_id' => $source->id]);
        $raw->fill([
            'external_identifier' => $item->externalIdentifier,
            'original_url' => $item->originalUrl,
            'title' => Str::limit($item->title, 500, '…'),
            'raw_content' => json_encode([
                'content' => $item->content,
                'author' => $item->author,
                'original_domain' => $item->originalDomain,
                'discovered_by' => $source->slug,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'content_hash' => $contentHash,
            'published_at' => $item->publishedAt,
            'detected_at' => $raw->detected_at ?? now(),
        ])->save();

        $location = $this->locations->extract($item->title, $item->content);
        $coordinates = $location !== null ? $this->geocoding->resolve($location['query']) : null;
        $status = $coordinates === null ? 'waiting_location' : 'pending_ai';

        ContentCandidate::query()->updateOrCreate(
            ['source_id' => $source->id, 'raw_item_id' => $raw->id],
            [
                'source_type' => $item->sourceType,
                'status' => $status,
                'normalized_content' => Str::limit(trim($item->title.' '.$item->content), 2000, '…'),
                'detected_location' => $location['query'] ?? null,
                'latitude' => $coordinates?->latitude,
                'longitude' => $coordinates?->longitude,
                'ai_status' => $coordinates === null ? null : 'pending',
                'ai_decision' => null,
                'next_retry_at' => now()->addMinutes($coordinates === null ? 30 : 5),
            ],
        );

        return $coordinates === null ? 'waiting_location' : 'queued';
    }
}
