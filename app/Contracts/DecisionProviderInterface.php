<?php

namespace App\Contracts;

use App\DTOs\CandidateDTO;
use App\DTOs\DecisionResult;
use App\DTOs\GeocodingResult;
use App\Models\Source;

interface DecisionProviderInterface
{
    public function decide(Source $source, CandidateDTO $candidate, ?GeocodingResult $location): DecisionResult;
}
