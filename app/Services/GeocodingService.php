<?php

namespace App\Services;

use App\DTOs\GeocodingResult;
use App\Enums\LocationAccuracy;
use App\Models\GeocodingCache;
use App\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GeocodingService
{
    private const NOMINATIM_ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    public function resolve(string $query): ?GeocodingResult
    {
        $query = trim($query);

        if ($query === '') {
            return null;
        }

        $normalized = $this->normalize($query);

        if ($cached = GeocodingCache::query()->where('normalized_query', $normalized)->first()) {
            $cached->increment('hit_count');
            $cached->forceFill(['last_used_at' => now()])->save();

            return new GeocodingResult(
                $cached->latitude,
                $cached->longitude,
                $cached->precision,
                $cached->provider,
                $query,
            );
        }

        if ($local = $this->findLocal($normalized)) {
            return $this->store($query, $normalized, $local);
        }

        if (Cache::get($this->missCacheKey($normalized)) === true) {
            return null;
        }

        if (config('alertapy.geocoding.provider') !== 'nominatim'
            || trim((string) config('alertapy.geocoding.user_agent')) === '') {
            return null;
        }

        $external = $this->findExternal($query);

        if ($external === null) {
            Cache::put($this->missCacheKey($normalized), true, now()->addMinutes(10));

            return null;
        }

        return $this->store($query, $normalized, $external);
    }

    private function findLocal(string $normalized): ?GeocodingResult
    {
        $location = Location::query()
            ->where('enabled', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where(function ($query) use ($normalized): void {
                $query
                    ->where('normalized_name', $normalized)
                    ->orWhere('normalized_query', $normalized)
                    ->orWhere('normalized_name', 'like', addcslashes($normalized, '%_\\').'%')
                    ->orWhere('normalized_query', 'like', addcslashes($normalized, '%_\\').'%')
                    ->orWhereHas('aliases', fn ($aliases) => $aliases->where('normalized_alias', $normalized));
            })
            ->orderByRaw('case when normalized_name = ? then 0 when normalized_query = ? then 1 else 2 end', [$normalized, $normalized])
            ->first();

        if ($location === null) {
            return null;
        }

        return new GeocodingResult(
            $location->latitude,
            $location->longitude,
            $this->precisionFromType($location->type),
            'local',
            $location->name,
        );
    }

    private function findExternal(string $query): ?GeocodingResult
    {
        if (config('alertapy.geocoding.provider') !== 'nominatim') {
            return null;
        }

        $userAgent = trim((string) config('alertapy.geocoding.user_agent'));

        if ($userAgent === '') {
            return null;
        }

        try {
            return Cache::lock('alertapy:geocoding:nominatim-lock', 10)->block(3, function () use ($query, $userAgent): ?GeocodingResult {
                $lastRequest = (float) Cache::get('alertapy:geocoding:nominatim-last-request', 0.0);
                $microseconds = (int) max(0, (1.0 - (microtime(true) - $lastRequest)) * 1_000_000);

                if ($microseconds > 0) {
                    usleep($microseconds);
                }

                $response = Http::acceptJson()
                    ->withUserAgent($userAgent)
                    ->timeout(8)
                    ->retry(2, 250)
                    ->get(self::NOMINATIM_ENDPOINT, [
                        'q' => $query.', Paraguay',
                        'format' => 'jsonv2',
                        'limit' => 1,
                        'countrycodes' => 'py',
                        'addressdetails' => 1,
                    ]);

                Cache::put('alertapy:geocoding:nominatim-last-request', microtime(true), now()->addMinute());

                if (! $response->successful()) {
                    return null;
                }

                $item = $response->json('0');

                if (! is_array($item) || ! is_numeric($item['lat'] ?? null) || ! is_numeric($item['lon'] ?? null)) {
                    return null;
                }

                $latitude = (float) $item['lat'];
                $longitude = (float) $item['lon'];

                if (! $this->insideParaguay($latitude, $longitude)) {
                    return null;
                }

                return new GeocodingResult(
                    $latitude,
                    $longitude,
                    $this->precisionFromType((string) ($item['addresstype'] ?? $item['type'] ?? 'approximate')),
                    'nominatim',
                    $query,
                );
            });
        } catch (Throwable) {
            return null;
        }
    }

    private function store(string $query, string $normalized, GeocodingResult $result): GeocodingResult
    {
        GeocodingCache::query()->updateOrCreate(
            ['normalized_query' => $normalized],
            [
                'query' => $query,
                'latitude' => $result->latitude,
                'longitude' => $result->longitude,
                'precision' => $result->precision,
                'provider' => $result->provider,
                'last_used_at' => now(),
            ],
        );

        Location::query()
            ->where('enabled', true)
            ->where(function ($locationQuery) use ($normalized): void {
                $locationQuery
                    ->where('normalized_name', $normalized)
                    ->orWhere('normalized_query', $normalized)
                    ->orWhereHas('aliases', fn ($aliases) => $aliases->where('normalized_alias', $normalized));
            })
            ->first()
            ?->forceFill([
                'latitude' => $result->latitude,
                'longitude' => $result->longitude,
                'accuracy' => $result->precision->value,
            ])->save();

        return new GeocodingResult(
            $result->latitude,
            $result->longitude,
            $result->precision,
            $result->provider,
            $query,
        );
    }

    private function normalize(string $query): string
    {
        $normalized = Str::lower(Str::ascii(trim($query)));

        return preg_replace('/\s+/', ' ', $normalized) ?: $normalized;
    }

    private function missCacheKey(string $normalized): string
    {
        return 'alertapy:geocoding:miss:'.hash('sha256', $normalized);
    }

    private function precisionFromType(string $type): LocationAccuracy
    {
        return match (Str::lower($type)) {
            'house', 'building' => LocationAccuracy::Exact,
            'road', 'street' => LocationAccuracy::Street,
            'neighbourhood', 'neighborhood', 'suburb', 'locality' => LocationAccuracy::Neighborhood,
            'city', 'town', 'village' => LocationAccuracy::City,
            'district', 'county', 'municipality' => LocationAccuracy::District,
            'state', 'department' => LocationAccuracy::Department,
            default => LocationAccuracy::Approximate,
        };
    }

    private function insideParaguay(float $latitude, float $longitude): bool
    {
        // Bounding box conservador: sirve como guardrail, no como cartografía oficial.
        return $latitude >= -27.7 && $latitude <= -19.1
            && $longitude >= -62.7 && $longitude <= -54.2;
    }
}
