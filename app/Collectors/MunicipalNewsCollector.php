<?php

namespace App\Collectors;

use App\Contracts\Collectors\MunicipalCollectorInterface;
use App\DTOs\CandidateDTO;
use App\Models\Source;
use Illuminate\Support\Collection;
use RuntimeException;

class MunicipalNewsCollector implements MunicipalCollectorInterface
{
    public function __construct(private readonly PressHomepageCollector $htmlCollector) {}

    /** @return Collection<int, CandidateDTO> */
    public function collect(Source $source): Collection
    {
        $city = $source->configuration_json['municipal_city'] ?? null;

        if (! is_string($city) || trim($city) === '') {
            throw new RuntimeException("{$source->name} no tiene una ciudad municipal configurada.");
        }

        return $this->htmlCollector->collect($source);
    }
}
