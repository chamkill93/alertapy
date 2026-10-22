<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NearbyInfrastructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NearbyInfrastructureController extends Controller
{
    public function __invoke(Request $request, NearbyInfrastructureService $service): JsonResponse
    {
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-27.7,-19.1'], 'lng' => ['required', 'numeric', 'between:-62.7,-54.2'], 'radius_km' => ['sometimes', 'numeric', 'in:1,2,3,5,10,25']]);
        return response()->json(['data' => $service->summary((float) $data['lat'], (float) $data['lng'], (float) ($data['radius_km'] ?? 2))]);
    }
}
