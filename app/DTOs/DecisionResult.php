<?php

namespace App\DTOs;

final readonly class DecisionResult
{
    /** @param array<string, float|int|string|bool|null> $scoreComponents */
    public function __construct(public string $action, public float $evidenceScore, public string $reason, public array $scoreComponents = []) {}
}
