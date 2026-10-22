<?php

namespace App\Services;

use App\DTOs\CandidateDTO;
use App\DTOs\GeocodingResult;
use App\Enums\SourceType;
use App\Models\Incident;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class CorroborationService
{
    public function findMatch(
        int $categoryId,
        CandidateDTO $candidate,
        GeocodingResult $location,
        CarbonImmutable $startsAt,
        ?string $city,
        ?string $department,
        Source $source,
    ): ?Incident {
        return Incident::query()
            ->where('category_id', $categoryId)
            ->whereBetween('starts_at', [$startsAt->subHours(12), $startsAt->addHours(12)])
            ->where('status', '!=', 'cancelled')
            ->whereDoesntHave('sources', fn ($query) => $query->where('source_id', $source->id))
            ->with('sources:id,incident_id,source_id')
            ->limit(100)
            ->get()
            ->filter(fn (Incident $incident): bool => $this->hasMatchingEvidence($candidate, $incident))
            ->map(fn (Incident $incident): array => [
                'incident' => $incident,
                'score' => $this->score($incident, $candidate, $location, $startsAt, $city, $department),
            ])
            ->filter(fn (array $match): bool => $match['score'] >= 65)
            ->sortByDesc('score')
            ->pluck('incident')
            ->first();
    }

    public function hasMatchingEvidence(CandidateDTO $candidate, Incident $incident): bool
    {
        // A shared city centroid and category are not evidence of the same event.
        // Remove those generic terms before comparing the two headlines.
        $generic = [
            'para', 'desde', 'sobre', 'entre', 'esta', 'este', 'como', 'tras', 'ante',
            'del', 'las', 'los', 'una', 'uno', 'con', 'por', 'que', 'barrio', 'zona',
            'ciudad', 'departamento', 'ruta', 'kilometro', 'kilometros', 'incendio', 'accidente', 'choque', 'colision',
            'afecta', 'registran', 'registra', 'reportan', 'reporta',
            'corte', 'cortes', 'agua', 'potable', 'luz', 'energia', 'electrica', 'electrico', 'electricidad',
            'servicio', 'servicios', 'suministro', 'suministros', 'interrupcion', 'interrupciones', 'apagon', 'apagones',
        ];
        $places = implode(' ', array_filter([
            $candidate->metadata['city'] ?? null,
            $candidate->metadata['department'] ?? null,
            $incident->city,
            $incident->department,
        ]));
        $ignored = [...$generic, ...$this->tokens($places)];
        $left = array_values(array_diff($this->tokens($candidate->title), $ignored));
        $right = array_values(array_diff($this->tokens($incident->title), $ignored));
        $shared = count(array_intersect($left, $right));
        $total = count(array_unique([...$left, ...$right]));

        return $shared >= 2 && $total > 0 && $shared / $total >= 0.5;
    }

    public function applyEvidence(Incident $incident): void
    {
        $sourceTypes = $incident->sources()
            ->with('source:id,type')
            ->get()
            ->pluck('source.type')
            ->filter()
            ->push($incident->source_type)
            ->map(fn (SourceType $type): string => $type->value)
            ->unique();
        $independentSources = $incident->sources()->distinct('source_id')->count('source_id');

        if ($incident->source_type === SourceType::Community) {
            $independentSources++;
        }

        $strongest = collect([
            SourceType::Official,
            SourceType::Sensor,
            SourceType::Press,
            SourceType::Community,
            SourceType::Social,
            SourceType::Discovery,
        ])->first(fn (SourceType $type): bool => $sourceTypes->contains($type->value));

        $incident->forceFill([
            'source_type' => $strongest ?? $incident->source_type,
            'verification_level' => $independentSources >= 2 ? 'corroborated' : $incident->verification_level,
        ])->save();
    }

    private function score(
        Incident $incident,
        CandidateDTO $candidate,
        GeocodingResult $location,
        CarbonImmutable $startsAt,
        ?string $city,
        ?string $department,
    ): float {
        $score = 20.0;
        $distance = $this->distanceKm($location->latitude, $location->longitude, (float) $incident->latitude, (float) $incident->longitude);
        $hours = abs($startsAt->diffInMinutes(CarbonImmutable::instance($incident->starts_at ?? $incident->published_at ?? now()))) / 60;

        $score += match (true) {
            $distance <= 1 => 30,
            $distance <= 5 => 22,
            $distance <= 15 => 10,
            default => -25,
        };
        $score += match (true) {
            $hours <= 1 => 20,
            $hours <= 6 => 12,
            default => 4,
        };

        if ($city !== null && $this->same($city, $incident->city)) {
            $score += 15;
        } elseif ($department !== null && $this->same($department, $incident->department)) {
            $score += 6;
        }

        $similarity = $this->textSimilarity($candidate->title.' '.$candidate->content, $incident->title.' '.$incident->summary);
        $score += $similarity * 25;

        $candidateRoute = $this->routeCode($candidate->title.' '.$candidate->content);
        $incidentRoute = $this->routeCode($incident->title.' '.$incident->summary);

        if ($candidateRoute !== null && $candidateRoute === $incidentRoute) {
            $score += 18;
        }

        $candidateKilometer = $this->routeKilometer($candidate->title.' '.$candidate->content);
        if ($candidateRoute !== null && $incident->road_code !== null && $candidateRoute === preg_replace('/\D/', '', $incident->road_code)) {
            $score += 20;
            if ($candidateKilometer !== null && $incident->road_kilometer !== null && abs($candidateKilometer - $incident->road_kilometer) <= (float) config('alertapy.roads.duplicate_km_tolerance', 1)) {
                $score += 35;
            }
        }

        return $score;
    }

    private function textSimilarity(string $left, string $right): float
    {
        $tokens = function (string $text): array {
            $normalized = Str::lower(Str::ascii(strip_tags($text)));
            $parts = preg_split('/[^a-z0-9]+/', $normalized) ?: [];
            $stop = ['para', 'desde', 'sobre', 'entre', 'esta', 'este', 'como', 'tras', 'ante', 'del', 'las', 'los', 'una', 'uno', 'con', 'por', 'que'];

            return array_values(array_unique(array_filter($parts, fn (string $token): bool => strlen($token) >= 4 && ! in_array($token, $stop, true))));
        };
        $a = $tokens($left);
        $b = $tokens($right);

        if ($a === [] || $b === []) {
            return 0.0;
        }

        return count(array_intersect($a, $b)) / count(array_unique([...$a, ...$b]));
    }

    /** @return array<int, string> */
    private function tokens(string $text): array
    {
        $parts = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii(strip_tags($text)))) ?: [];

        return array_values(array_unique(array_filter($parts, fn (string $token): bool => strlen($token) >= 4 && preg_match('/^(?:py)?\d+$/', $token) !== 1)));
    }

    private function routeCode(string $text): ?string
    {
        return preg_match('/\b(?:ruta\s*)?(?:py[-\s]?)?(\d{1,2})\b/iu', Str::ascii($text), $match) === 1
            ? str_pad($match[1], 2, '0', STR_PAD_LEFT)
            : null;
    }

    private function routeKilometer(string $text): ?float
    {
        return preg_match('/\b(?:km|kil[oó]metros?)\.?\s*(\d{1,4}(?:[\.,]\d+)?)/iu', Str::ascii($text), $match) === 1
            ? (float) str_replace(',', '.', $match[1])
            : null;
    }

    private function same(string $left, ?string $right): bool
    {
        return $right !== null && Str::lower(Str::ascii($left)) === Str::lower(Str::ascii($right));
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
