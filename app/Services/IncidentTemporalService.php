<?php

namespace App\Services;

use App\Enums\TemporalAccuracy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Throwable;

class IncidentTemporalService
{
    /** @param array<string, mixed> $metadata
     *  @return array{occurred_at: ?CarbonImmutable, accuracy: TemporalAccuracy, evidence: ?array}
     */
    public function resolve(string $text, ?CarbonInterface $publishedAt, array $metadata = []): array
    {
        $explicit = $metadata['occurred_at'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '') {
            try {
                $date = CarbonImmutable::parse($explicit, config('app.timezone'));
                $accuracy = TemporalAccuracy::tryFrom(Str::lower((string) ($metadata['occurred_at_accuracy'] ?? 'exact')))
                    ?? TemporalAccuracy::Exact;
                return $this->result($date, $accuracy, $explicit);
            } catch (Throwable) {
                // Una fecha inválida se degrada a desconocida; nunca se inventa.
            }
        }

        if ($publishedAt === null) {
            return $this->unknown();
        }

        $reference = CarbonImmutable::instance($publishedAt);
        $plain = Str::lower(Str::ascii($text));
        if (preg_match('/\bhace\s+(\d{1,3})\s+(minuto|minutos|hora|horas)\b/', $plain, $match) === 1) {
            $amount = (int) $match[1];
            $date = str_starts_with($match[2], 'hora') ? $reference->subHours($amount) : $reference->subMinutes($amount);
            return $this->result($date, TemporalAccuracy::RelativeParsed, $match[0]);
        }

        if (preg_match('/\b(anoche|ayer)\b/', $plain, $relative) === 1) {
            $date = $reference->subDay();
            $accuracy = TemporalAccuracy::DateOnly;
            if (preg_match('/\b(?:a\s+las|cerca\s+de\s+las)\s+(\d{1,2})(?::(\d{2}))?\b/', $plain, $time) === 1) {
                $date = $date->setTime(min(23, (int) $time[1]), isset($time[2]) ? min(59, (int) $time[2]) : 0);
                $accuracy = TemporalAccuracy::Approximate;
            } elseif ($relative[1] === 'anoche') {
                $date = $date->setTime(22, 0);
                $accuracy = TemporalAccuracy::Approximate;
            } else {
                $date = $date->startOfDay();
            }
            return $this->result($date, $accuracy, $relative[0]);
        }

        if (str_contains($plain, 'esta madrugada')) {
            return $this->result($reference->startOfDay()->addHours(3), TemporalAccuracy::Approximate, 'esta madrugada');
        }

        return $this->unknown();
    }

    private function result(CarbonImmutable $date, TemporalAccuracy $accuracy, string $expression): array
    {
        return ['occurred_at' => $date, 'accuracy' => $accuracy, 'evidence' => [
            'original_expression' => $expression,
            'parsed_at' => $date->toIso8601String(),
            'accuracy' => $accuracy->value,
        ]];
    }

    private function unknown(): array
    {
        return ['occurred_at' => null, 'accuracy' => TemporalAccuracy::Unknown, 'evidence' => null];
    }
}
