<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Builder;

class IncidentQueryService
{
    /** @param array<string, mixed> $filters */
    public function apply(Builder $query, array $filters): Builder
    {
        $query->published()->withinRetention();

        if (! empty($filters['categories'])) {
            $query->whereHas('category', function (Builder $query) use ($filters): void {
                $query->whereIn('slug', $filters['categories']);
            });
        }

        $this->applyPeriod($query, $filters['period'] ?? 'now');

        if (! empty($filters['bbox'])) {
            $bbox = $filters['bbox'];
            $query
                ->whereBetween('latitude', [$bbox['south'], $bbox['north']])
                ->whereBetween('longitude', [$bbox['west'], $bbox['east']]);
        }

        if (! empty($filters['city'])) {
            $query->where('city', 'like', '%'.addcslashes($filters['city'], '%_\\').'%');
        }

        if (! empty($filters['status'])) {
            $query->whereIn('status', $filters['status']);
        }

        if (! empty($filters['source'])) {
            $sourceTypes = array_column(SourceType::cases(), 'value');

            if (in_array($filters['source'], $sourceTypes, true)) {
                $query->where('source_type', $filters['source']);
            } else {
                $query->whereHas('sources.source', function (Builder $query) use ($filters): void {
                    $query->where('slug', $filters['source']);
                });
            }
        }

        if (! empty($filters['road'])) {
            $query->where('road_code', preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $filters['road'])));
        }

        if (isset($filters['near_lat'], $filters['near_lng'], $filters['radius_km'])) {
            $this->applyRadius(
                $query,
                (float) $filters['near_lat'],
                (float) $filters['near_lng'],
                (float) $filters['radius_km'],
            );
        }

        return $query;
    }

    private function applyRadius(Builder $query, float $latitude, float $longitude, float $radiusKm): void
    {
        $latitudeDelta = $radiusKm / 111.0;
        $longitudeDelta = $radiusKm / max(1.0, 111.0 * cos(deg2rad($latitude)));
        $query
            ->whereBetween('latitude', [$latitude - $latitudeDelta, $latitude + $latitudeDelta])
            ->whereBetween('longitude', [$longitude - $longitudeDelta, $longitude + $longitudeDelta])
            ->whereRaw(
                '(6371 * 2 * ASIN(SQRT(POWER(SIN(RADIANS(latitude - ?) / 2), 2) + COS(RADIANS(?)) * COS(RADIANS(latitude)) * POWER(SIN(RADIANS(longitude - ?) / 2), 2)))) <= ?',
                [$latitude, $latitude, $longitude, $radiusKm],
            );
    }

    private function applyPeriod(Builder $query, string $period): void
    {
        $query->where('status', '!=', IncidentStatus::Cancelled);

        match ($period) {
            'now' => $query->current(),
            '1h' => $query->where('published_at', '>=', now()->subHour()),
            '6h' => $query->where('published_at', '>=', now()->subHours(6)),
            '24h' => $query->where('published_at', '>=', now()->subDay()),
            '7d' => $query->where('published_at', '>=', now()->subDays(7)),
            default => $query->current(),
        };
    }
}
