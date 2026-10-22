<?php

namespace App\Services;

use App\Contracts\DecisionProviderInterface;
use App\DTOs\CandidateDTO;
use App\DTOs\DecisionResult;
use App\DTOs\GeocodingResult;
use App\Models\Source;

class OpenCodeDecisionProvider implements DecisionProviderInterface
{
    public function decide(Source $source, CandidateDTO $candidate, ?GeocodingResult $location): DecisionResult
    {
        return new DecisionResult('WAIT', 0, 'OpenCode está desactivado o no disponible; fallback seguro WAIT.');
    }
}
