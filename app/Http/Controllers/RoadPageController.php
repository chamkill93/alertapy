<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\Road;
use App\Services\RoadNameNormalizer;
use Illuminate\View\View;

class RoadPageController extends Controller
{
    public function __invoke(string $code, RoadNameNormalizer $normalizer): View
    {
        $normalized = $normalizer->normalize($code); $road = Road::query()->active()->where('normalized_code', $normalized)->firstOrFail();
        $base = Incident::query()->published()->where('road_id', $road->id);
        return view('roads.show', ['road' => $road, 'active' => (clone $base)->current()->latest('published_at')->get(), 'count24h' => (clone $base)->where('published_at', '>=', now()->subDay())->count(), 'count7d' => (clone $base)->where('published_at', '>=', now()->subDays(7))->count()]);
    }
}
