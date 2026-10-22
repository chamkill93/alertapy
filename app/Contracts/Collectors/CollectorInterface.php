<?php

namespace App\Contracts\Collectors;

use App\DTOs\CandidateDTO;
use App\Models\Source;
use Illuminate\Support\Collection;

interface CollectorInterface
{
    /** @return Collection<int, CandidateDTO> */
    public function collect(Source $source): Collection;
}
