<?php

namespace App\Collectors;

use App\Contracts\Collectors\CollectorInterface;
use App\DTOs\CandidateDTO;
use App\Models\Source;
use App\Services\TerritorialCandidateExpander;
use Illuminate\Support\Collection;
use RuntimeException;

class RegionalPressCollector implements CollectorInterface
{
    public function __construct(
        private readonly PressHomepageCollector $htmlCollector,
        private readonly TerritorialCandidateExpander $expander,
    ) {}

    /** @return Collection<int, CandidateDTO> */
    public function collect(Source $source): Collection
    {
        $department = $source->configuration_json['regional_department'] ?? null;

        if (! is_string($department) || trim($department) === '') {
            throw new RuntimeException("{$source->name} no tiene un departamento regional configurado.");
        }

        return $this->expander->expand($this->htmlCollector->collect($source));
    }
}
