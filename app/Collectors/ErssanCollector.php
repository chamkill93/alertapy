<?php

namespace App\Collectors;

use App\Contracts\Collectors\CollectorInterface;
use App\DTOs\CandidateDTO;
use App\Models\Source;
use App\Services\TerritorialCandidateExpander;
use Illuminate\Support\Collection;

class ErssanCollector implements CollectorInterface
{
    public function __construct(
        private readonly PressHomepageCollector $collector,
        private readonly TerritorialCandidateExpander $expander,
    ) {}

    /** @return Collection<int, CandidateDTO> */
    public function collect(Source $source): Collection
    {
        return $this->expander->expand($this->collector->collect($source));
    }
}
