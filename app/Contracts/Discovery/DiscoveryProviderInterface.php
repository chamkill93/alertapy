<?php

namespace App\Contracts\Discovery;

use App\DTOs\DiscoveryItemDTO;
use App\Models\Source;
use Illuminate\Support\Collection;

interface DiscoveryProviderInterface
{
    /** @return Collection<int, DiscoveryItemDTO> */
    public function discover(Source $source): Collection;
}
