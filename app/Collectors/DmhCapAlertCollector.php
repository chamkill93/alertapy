<?php

namespace App\Collectors;

use App\Models\WeatherAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

class DmhCapAlertCollector
{
    /** @return array{received: int, stored: int} */
    public function sync(): array
    {
        $response = Http::acceptJson()->timeout(60)->retry(2, 500)->get((string) config('alertapy.sources.dmh-cap.messages_url'), [
            'f' => 'json', 'limit' => 100, 'metadata_id' => 'urn:wmo:md:py-dinac-dmh:cap',
        ]);
        $response->throw();
        $features = $response->json('features', []);
        $stored = 0;
        foreach ($features as $feature) {
            $properties = $feature['properties'] ?? [];
            $encoded = $properties['content']['value'] ?? null;
            if (! is_string($encoded) || ($xml = base64_decode($encoded, true)) === false) continue;
            $identifier = $this->tag($xml, 'identifier') ?: (string) ($properties['id'] ?? $feature['id'] ?? '');
            $event = $this->tag($xml, 'event');
            $headline = $this->tag($xml, 'headline') ?: $event;
            if ($identifier === '' || $event === '') continue;
            $expiresAt = $this->date($this->tag($xml, 'expires'));
            if ($expiresAt?->isPast()) continue;
            WeatherAlert::query()->updateOrCreate(
                ['external_id' => $identifier],
                [
                    'event' => $event, 'headline' => $headline, 'description' => $this->tag($xml, 'description'),
                    'severity' => $this->tag($xml, 'severity'), 'urgency' => $this->tag($xml, 'urgency'), 'certainty' => $this->tag($xml, 'certainty'),
                    'area_description' => $this->tag($xml, 'areaDesc'), 'geometry_geojson' => $this->polygon($this->tag($xml, 'polygon')),
                    'sent_at' => $this->date($this->tag($xml, 'sent')), 'starts_at' => $this->date($this->tag($xml, 'onset')), 'expires_at' => $expiresAt,
                    'source_url' => (string) config('alertapy.sources.dmh-cap.source_url'), 'synced_at' => now(),
                ],
            );
            $stored++;
        }
        return ['received' => count($features), 'stored' => $stored];
    }

    private function tag(string $xml, string $tag): string
    {
        return preg_match('/<(?:\w+:)?'.preg_quote($tag, '/').'(?:\s[^>]*)?>(.*?)<\/(?:\w+:)?'.preg_quote($tag, '/').'>/si', $xml, $match) === 1
            ? trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_XML1, 'UTF-8')) : '';
    }

    private function date(string $value): ?CarbonImmutable
    {
        if ($value === '') return null;
        try { return CarbonImmutable::parse($value); } catch (\Throwable) { return null; }
    }

    /** @return array<string, mixed>|null */
    private function polygon(string $value): ?array
    {
        if ($value === '') return null;
        $coordinates = collect(preg_split('/\s+/', trim($value)) ?: [])->map(function (string $pair): ?array {
            $parts = array_map('trim', explode(',', $pair));
            return count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1]) ? [(float) $parts[1], (float) $parts[0]] : null;
        })->filter()->values()->all();
        return count($coordinates) >= 3 ? ['type' => 'Polygon', 'coordinates' => [$coordinates]] : null;
    }
}
