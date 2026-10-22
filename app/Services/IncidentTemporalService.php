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
        $context = $this->occurrenceContext($plain);
        if ($context === null) {
            return $this->unknown();
        }

        $expression = $context['expression'];
        if (preg_match('/\bhace\s+(\d{1,3})\s+(minuto|minutos|hora|horas)\b/', $expression, $match) === 1) {
            $amount = (int) $match[1];
            $date = str_starts_with($match[2], 'hora') ? $reference->subHours($amount) : $reference->subMinutes($amount);
            return $this->result($date, TemporalAccuracy::RelativeParsed, $match[0]);
        }

        if (preg_match('/\b(anoche|ayer)\b/', $expression, $relative) === 1) {
            $date = $reference->subDay();
            $accuracy = TemporalAccuracy::DateOnly;
            if (preg_match('/\b(?:a\s+las|cerca\s+de\s+las)\s+(\d{1,2})(?::(\d{2}))?\b/', $context['following'], $time) === 1) {
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

        if ($expression === 'esta madrugada') {
            return $this->result($reference->startOfDay()->addHours(3), TemporalAccuracy::Approximate, 'esta madrugada');
        }

        return $this->unknown();
    }

    /** @return array{expression: string, following: string}|null */
    private function occurrenceContext(string $text): ?array
    {
        preg_match_all('/\b(?:hace\s+\d{1,3}\s+(?:minutos?|horas?)|anoche|ayer|esta madrugada)\b/', $text, $matches, PREG_OFFSET_CAPTURE);
        $eventVerb = '(?:ocurrio|ocurrieron|sucedio|sucedieron|se\s+(?:registro|registraron|produjo|produjeron|desato|desataron)|tuvo\s+lugar|tuvieron\s+lugar|hubo)';
        $eventNoun = '(?:accidente|choque|colision|vuelco|incendio|explosion|corte|interrupcion|apagon|inundacion|desborde|asalto|robo|homicidio|balacera|derrumbe|sismo|temporal|tormenta|operativo)';
        $contexts = [];

        foreach ($matches[0] as [$expression, $offset]) {
            $clauses = preg_split('/[.!?;,\n]/', substr($text, 0, $offset));
            $before = (string) end($clauses);
            $following = preg_split('/[.!?;,\n]/', substr($text, $offset + strlen($expression)), 2)[0];

            // An article's update time, an accumulated measurement, or a period
            // that is still ongoing does not date the main incident.
            if (preg_match('/\bdesde\s*$/', $before) === 1
                || preg_match('/\b(?:actualiz\w*|publica\w*|edita\w*|emitid\w*|anunciad\w*|acumulad\w*|medicion\w*|balance\w*|estadistic\w*)(?:\s+\w+){0,6}\s*$/', $before) === 1
                || preg_match('/\b(?:no|nunca|tampoco|sin)(?:\s+\w+){0,8}\s*$/', $before) === 1) {
                continue;
            }

            $assertedBefore = preg_match('/\b'.$eventVerb.'(?:\s+\w+){0,6}\s*$/', $before) === 1;
            $namedBefore = preg_match('/\b'.$eventNoun.'(?:\s+(?:fatal|grave|vial))?\s*$/', $before) === 1;
            $assertedAfter = preg_match('/^\s*'.$eventVerb.'\b/', $following) === 1;
            if ($assertedAfter && preg_match('/^.{0,60}\b(?:actualiz\w*|publica\w*|acumulad\w*|medicion\w*|balance\w*)\b/', $following) === 1) {
                $assertedAfter = false;
            }
            if (! $assertedBefore && ! $namedBefore && ! $assertedAfter) {
                continue;
            }

            // Keep a later publication/update time or a second event outside
            // the expression's clock-time context.
            $following = preg_split('/\b(?:actualiz\w*|publica\w*|hace|ayer|anoche|pero)\b/', $following, 2)[0];
            $following = substr($following, 0, 120);
            preg_match('/\b(?:a\s+las|cerca\s+de\s+las)\s+\d{1,2}(?::\d{2})?\b/', $following, $clockTime);
            $contexts[$expression.'|'.($clockTime[0] ?? '')] = ['expression' => $expression, 'following' => $following];
        }

        // Multiple occurrence dates may describe different events. Preserve
        // that uncertainty rather than selecting whichever token came first.
        return count($contexts) === 1 ? array_values($contexts)[0] : null;
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
