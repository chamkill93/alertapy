<?php

namespace App\Services;

use App\Models\Location;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ParaguayLocationExtractor
{
    /** @var Collection<int, Location>|null */
    private ?Collection $locations = null;

    /** @return array{text: string, query: string, city: ?string, department: ?string, accuracy: string}|null */
    public function extract(string ...$texts): ?array
    {
        foreach ($texts as $text) {
            $match = $this->firstMatch($this->normalize($text));

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /** @return array<int, array{text: string, query: string, city: ?string, department: ?string, accuracy: string}> */
    public function extractAll(string ...$texts): array
    {
        $matches = collect();

        foreach ($texts as $text) {
            $normalized = $this->normalize($text);

            foreach ($this->catalog() as $location) {
                foreach ($location->aliases as $alias) {
                    if (preg_match('/(?<![\pL\pN])'.preg_quote($alias->normalized_alias, '/').'(?![\pL\pN])/u', $normalized, $found, PREG_OFFSET_CAPTURE) !== 1) {
                        continue;
                    }

                    $matches->put($location->id, [
                        'position' => $found[0][1],
                        'text' => $location->name,
                        'query' => $location->geocoding_query ?: $location->name,
                        'city' => $location->city,
                        'department' => $location->department,
                        'accuracy' => $location->accuracy,
                    ]);
                    break;
                }
            }
        }

        $specificDepartments = $matches
            ->filter(fn (array $match): bool => $match['city'] !== null)
            ->pluck('department')
            ->filter()
            ->unique();

        return $matches
            ->reject(fn (array $match): bool => $match['city'] === null && $specificDepartments->contains($match['department']))
            ->sortBy('position')
            ->map(function (array $match): array {
                unset($match['position']);

                return $match;
            })
            ->values()
            ->all();
    }

    /** @return array{text: string, query: string, city: ?string, department: ?string, accuracy: string}|null */
    private function firstMatch(string $text): ?array
    {
        $best = null;

        foreach ($this->catalog() as $location) {
            foreach ($location->aliases as $alias) {
                $normalizedAlias = $alias->normalized_alias;

                if (! preg_match('/(?<![\pL\pN])'.preg_quote($normalizedAlias, '/').'(?![\pL\pN])/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                $candidate = [
                    'position' => $matches[0][1],
                    'length' => mb_strlen($normalizedAlias),
                    'priority' => $alias->priority,
                    'location' => $location,
                ];

                if ($best === null || $this->isBetterMatch($candidate, $best)) {
                    $best = $candidate;
                }
            }
        }

        if ($best === null) {
            return null;
        }

        /** @var Location $location */
        $location = $best['location'];

        return [
            'text' => $location->name,
            'query' => $location->geocoding_query ?: $location->name,
            'city' => $location->city,
            'department' => $location->department,
            'accuracy' => $location->accuracy,
        ];
    }

    /** @param array{position: int, length: int, priority: int, location: Location} $candidate
     * @param  array{position: int, length: int, priority: int, location: Location}  $current
     */
    private function isBetterMatch(array $candidate, array $current): bool
    {
        if ($candidate['position'] !== $current['position']) {
            return $candidate['position'] < $current['position'];
        }

        if ($candidate['length'] !== $current['length']) {
            return $candidate['length'] > $current['length'];
        }

        return $candidate['priority'] < $current['priority'];
    }

    /** @return Collection<int, Location> */
    private function catalog(): Collection
    {
        return $this->locations ??= Location::query()
            ->where('enabled', true)
            ->whereHas('aliases')
            ->with('aliases:id,location_id,alias,normalized_alias,priority')
            ->get();
    }

    private function normalize(string $value): string
    {
        $value = Str::lower(Str::ascii(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return trim(preg_replace('/\s+/u', ' ', $value) ?: $value);
    }
}
