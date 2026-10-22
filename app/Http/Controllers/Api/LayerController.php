<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MonitoringStation;
use Illuminate\Http\JsonResponse;

class LayerController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'incidents' => Category::query()->where('enabled', true)->orderBy('sort_order')->get(['slug', 'name', 'icon', 'color']),
                'monitoring' => MonitoringStation::query()->where('is_active', true)->distinct()->orderBy('kind')->pluck('kind')->values(),
                'representations' => ['point', 'cluster', 'line', 'polygon', 'station', 'heatmap'],
            ],
        ]);
    }
}
