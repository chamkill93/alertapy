<?php

namespace App\Services;

use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Incident;
use App\Models\IncidentSource;
use App\Models\IncidentUpdate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IncidentDeduplicator
{
    /** @return array{groups: int, merged: int} */
    public function consolidateOfficialElectricity(): array
    {
        $categoryId = Category::query()->where('slug', 'electricity')->value('id');

        if ($categoryId === null) {
            return ['groups' => 0, 'merged' => 0];
        }

        $groups = Incident::query()
            ->where('category_id', $categoryId)
            ->where('source_type', SourceType::Official)
            ->whereNotNull('starts_at')
            ->with(['sources', 'updates'])
            ->get()
            ->groupBy(fn (Incident $incident): string => $this->groupKey($incident))
            ->filter(fn (Collection $incidents): bool => $incidents->count() > 1);
        $stats = ['groups' => 0, 'merged' => 0];

        foreach ($groups as $incidents) {
            DB::transaction(function () use ($incidents, &$stats): void {
                $ordered = $incidents->sortBy([
                    [fn (Incident $incident) => $incident->starts_at?->getTimestamp() ?? PHP_INT_MAX, 'asc'],
                    ['id', 'asc'],
                ])->values();
                /** @var Incident $keeper */
                $keeper = $ordered->first();
                $duplicates = $ordered->slice(1);
                $all = collect([$keeper])->merge($duplicates);
                $summaries = $all
                    ->flatMap(fn (Incident $incident): array => preg_split('/\n{2,}/u', $incident->summary) ?: [])
                    ->map(fn (string $summary): string => trim($summary))
                    ->filter()
                    ->unique()
                    ->values();
                $place = $keeper->city ?? $keeper->department ?? $keeper->location_text;
                $startsAt = $all->pluck('starts_at')->filter()->sort()->first();
                $endsAt = $all->pluck('ends_at')->filter()->sortDesc()->first();
                $expiresAt = $all->pluck('expires_at')->filter()->sortDesc()->first();
                $publishedAt = $all->pluck('published_at')->filter()->sort()->first();

                foreach ($duplicates as $duplicate) {
                    foreach ($duplicate->sources as $sourceReference) {
                        IncidentSource::query()->firstOrCreate(
                            [
                                'incident_id' => $keeper->id,
                                'raw_item_id' => $sourceReference->raw_item_id,
                            ],
                            [
                                'source_id' => $sourceReference->source_id,
                                'original_title' => $sourceReference->original_title,
                                'original_url' => $sourceReference->original_url,
                                'published_at' => $sourceReference->published_at,
                                'detected_at' => $sourceReference->detected_at,
                            ],
                        );

                        IncidentUpdate::query()->firstOrCreate(
                            [
                                'incident_id' => $keeper->id,
                                'raw_item_id' => $sourceReference->raw_item_id,
                            ],
                            [
                                'source_id' => $sourceReference->source_id,
                                'title' => $duplicate->title,
                                'summary' => $duplicate->summary,
                                'status' => $duplicate->status,
                                'occurred_at' => $duplicate->starts_at ?? $sourceReference->detected_at,
                            ],
                        );
                    }

                    $duplicate->updates()->update(['incident_id' => $keeper->id]);
                    $duplicate->delete();
                    $stats['merged']++;
                }

                $keeper->forceFill([
                    'title' => "Trabajos programados de ANDE en {$place}",
                    'summary' => $summaries->implode("\n\n"),
                    'location_text' => $place,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'expires_at' => $expiresAt ?? $endsAt,
                    'published_at' => $publishedAt,
                ])->save();
                $stats['groups']++;
            });
        }

        return $stats;
    }

    private function groupKey(Incident $incident): string
    {
        $place = $incident->city !== null
            ? 'city:'.$incident->city
            : 'department:'.($incident->department ?? $incident->location_text);

        return Str::lower(Str::ascii($place)).'|'.$incident->starts_at?->toDateString();
    }
}
