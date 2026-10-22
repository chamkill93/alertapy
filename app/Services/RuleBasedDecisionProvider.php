<?php

namespace App\Services;

use App\Contracts\DecisionProviderInterface;
use App\DTOs\CandidateDTO;
use App\DTOs\DecisionResult;
use App\DTOs\GeocodingResult;
use App\Enums\SourceType;
use App\Models\Source;

class RuleBasedDecisionProvider implements DecisionProviderInterface
{
    public function __construct(
        private readonly EvidenceScorer $scorer,
        private readonly ClosingRuleEngine $closing,
        private readonly ?TerritorialRelevanceClassifier $relevance = null,
    ) {}

    public function decide(Source $source, CandidateDTO $candidate, ?GeocodingResult $location): DecisionResult
    {
        $evidence = $this->scorer->score($source, $candidate, $location); $score = $evidence['score'];
        if (($evidence['components']['personal_accusation'] ?? 0) < 0) return new DecisionResult('REJECT', $score, 'Acusación personal no publicable automáticamente.', $evidence['components']);
        if (! filter_var($candidate->originalUrl, FILTER_VALIDATE_URL) || ($candidate->publishedAt === null && empty($candidate->metadata['starts_at']))) return new DecisionResult('REJECT', $score, 'URL o fecha inválida.', $evidence['components']);
        if ($location === null) return new DecisionResult('WAIT', $score, 'Falta ubicación suficiente.', $evidence['components']);
        if ($this->closing->containsClosingLanguage($candidate->title.' '.$candidate->content)) return new DecisionResult('CLOSE', $score, 'Lenguaje de cierre; requiere coincidencia con un incidente.', $evidence['components']);
        $relevance = ($this->relevance ?? app(TerritorialRelevanceClassifier::class))->classify($candidate->title.' '.$candidate->content);
        if ($relevance['decision'] === 'REJECT') {
            return new DecisionResult('REJECT', $score, $relevance['reason'], [...$evidence['components'], 'relevance_rule' => $relevance['rule_id'], 'matched_tokens' => $relevance['matched_tokens']]);
        }
        if ($source->type === SourceType::Press && ! config('alertapy.ingestion.local_rules_publish', true)) return new DecisionResult('WAIT', $score, 'La publicación determinística de prensa fue desactivada explícitamente.', $evidence['components']);
        if (in_array($source->type, [SourceType::Official, SourceType::Sensor, SourceType::Press], true)) return new DecisionResult('PUBLISH', $score, 'Fuente permitida, trazable, fechada y territorial.', $evidence['components']);
        return new DecisionResult('WAIT', $score, 'Discovery y social no publican automáticamente.', $evidence['components']);
    }
}
