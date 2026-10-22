<?php

namespace App\Services;

use App\DTOs\CandidateDTO;
use App\Models\Incident;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class ClosingRuleEngine
{
    public function containsClosingLanguage(string $text): bool
    {
        $normalized = Str::lower(Str::ascii($text));
        $patterns = [
            'restablecid\w*|restablec(?:e|en|io|ieron)',
            'normalizad\w*|normaliz(?:a|an|o|aron)',
            '(?:habilitad|reabiert|extinguid|controlad|finalizad|culminad|levantad)\w*',
            'servicio normal|transito normal|circulacion normal',
            '(?:confirm(?:a|an|o|aron)|complet(?:a|an|o|aron)|concret(?:a|an|o|aron)|logr(?:a|an|o|aron))\s+(?:el\s+|la\s+)?(?:total\s+|completo\s+)?(?:restablecimiento|normalizacion)',
            '(?:restablecimiento|normalizacion)\s+(?:(?:del?|la|el|servicio|suministro|electrico|electrica|energia|agua|potable)\s+){0,7}(?:(?:fue|esta|ha\s+sido)\s+)?(?:confirmad[oa]|completad[oa]|concretad[oa]|lograd[oa])',
        ];
        preg_match_all('/\b(?:'.implode('|', $patterns).')\b/u', $normalized, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$match, $offset]) {
            // Evaluate the assertion, not an unrelated negative sentence earlier
            // in the bulletin ("No hubo heridos; el servicio fue restablecido").
            $clauses = preg_split('/[.!?;,\n]|\b(?:pero|sin embargo)\b/u', substr($normalized, 0, $offset));
            $prefix = (string) end($clauses);
            $remainingClauses = preg_split('/[.!?;,\n]|\b(?:pero|sin embargo)\b/u', substr($normalized, $offset + strlen($match)), 2);
            $assertion = $prefix.$match.($remainingClauses[0] ?? '');
            $unconfirmed = '/\b(?:no(?!\s+solo\b)|nunca|jamas|sin|sera|seran|seria|serian|estara|estaran|estaria|estarian|podra|podran|podria|podrian|debera|deberan|deberia|deberian|espera|esperan|esperamos|preve|preven)\b(?:\s+\w+){0,8}\s*$/u';

            if (preg_match($unconfirmed, $prefix) === 1
                || preg_match('/\b(?:parcialmente|parcial(?:es)?|en parte|por sectores)\b/u', $assertion) === 1) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function findMatching(int $categoryId, ?string $city, ?string $department, CarbonInterface $occurredAt, string $text, ?CandidateDTO $candidate = null, ?int $rawItemId = null): ?Incident
    {
        if (! $this->containsClosingLanguage($text)) {
            return null;
        }

        $incidents = Incident::query()->current()->where('category_id', $categoryId)
            ->whereBetween('starts_at', [$occurredAt->copy()->subDays(7), $occurredAt])
            ->when($city, fn ($query) => $query->where('city', $city), fn ($query) => $department ? $query->where('department', $department) : $query)
            ->with('sources:id,incident_id,raw_item_id')->get();

        // An update to the same archived source item identifies its existing event.
        // A shared homepage URL alone would also match unrelated bulletins.
        if ($rawItemId !== null) {
            $sameItem = $incidents->filter(fn (Incident $incident): bool => $incident->sources->contains('raw_item_id', $rawItemId));
            if ($sameItem->isNotEmpty()) {
                return $sameItem->count() === 1 ? $sameItem->first() : null;
            }
        }

        if (! $city && ! $department) {
            return null;
        }

        $matches = $incidents->filter(fn (Incident $incident): bool => $this->hasEventIdentity($candidate?->title ?? $text, $incident));

        // Ambiguous closures require review, even if one incident is newer.
        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function hasEventIdentity(string $title, Incident $incident): bool
    {
        $places = implode(' ', array_filter([$incident->city, $incident->department]));
        $ignored = [
            'del', 'las', 'los', 'una', 'uno', 'para', 'desde', 'sobre', 'entre', 'tras', 'ante', 'por', 'con', 'sin',
            'barrio', 'zona', 'ciudad', 'departamento', 'incendio', 'accidente', 'choque', 'colision',
            'servicio', 'agua', 'energia', 'electrica', 'electricidad', 'suministro', 'corte',
            ...$this->tokens($places),
        ];
        $left = array_values(array_diff($this->tokens($title), $ignored));
        $right = array_values(array_diff($this->tokens($incident->title), $ignored));
        $directions = ['norte', 'sur', 'este', 'oeste'];
        $leftDirections = array_intersect($left, $directions);
        $rightDirections = array_intersect($right, $directions);
        if ($leftDirections !== [] && $rightDirections !== [] && array_diff($leftDirections, $rightDirections) !== []) {
            return false;
        }
        $shared = count(array_intersect($left, $right));
        $total = count(array_unique([...$left, ...$right]));

        return $shared >= 2 && $total > 0 && $shared / $total >= 0.75;
    }

    /** @return array<int, string> */
    private function tokens(string $text): array
    {
        $parts = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii(strip_tags($text)))) ?: [];

        return array_values(array_unique(array_filter($parts, fn (string $token): bool => (strlen($token) >= 3 || ctype_digit($token))
            && preg_match('/^(?:restablecid|normalizad|habilitad|reabiert|extinguid|controlad|finalizad|culminad|levantad|interrumpid|suspendid|programad|afectad)\w*$/', $token) !== 1
        )));
    }
}
