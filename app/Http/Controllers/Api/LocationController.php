<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LocationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $normalized = Str::lower(Str::ascii(trim($validated['query'])));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?: $normalized;

        $locations = Location::query()
            ->with('parent:id,name,type')
            ->where('enabled', true)
            ->where(function ($query) use ($normalized): void {
                $query
                    ->where('normalized_name', 'like', addcslashes($normalized, '%_\\').'%')
                    ->orWhere('normalized_query', 'like', addcslashes($normalized, '%_\\').'%')
                    ->orWhereHas('aliases', fn ($aliases) => $aliases->where('normalized_alias', 'like', addcslashes($normalized, '%_\\').'%'));
            })
            ->orderByRaw("case type when 'city' then 1 when 'district' then 2 when 'neighborhood' then 3 else 4 end")
            ->limit(10)
            ->get()
            ->map(fn (Location $location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'type' => $location->type,
                'parent' => $location->parent?->name,
                'city' => $location->city,
                'department' => $location->department,
                'accuracy' => $location->accuracy,
                'latitude' => $location->latitude,
                'longitude' => $location->longitude,
            ]);

        return response()->json(['data' => $locations]);
    }
}
