<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FavoriteZone;
use App\Models\Incident;
use App\Services\GeoDistanceService;
use App\Services\IncidentQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class FavoriteZoneController extends Controller
{
    private const COOKIE = 'alertapy_favorite_token';

    public function index(Request $request): JsonResponse
    {
        [$hash, $plain] = $this->identity($request);
        return $this->response(['data' => $this->owned($request, $hash)->orderBy('name')->get()->map(fn ($zone) => $this->data($zone))], $plain);
    }

    public function store(Request $request): JsonResponse
    {
        [$hash, $plain] = $this->identity($request); $data = $this->validated($request);
        abort_if($this->owned($request, $hash)->count() >= (int) config('alertapy.favorites.max_zones', 10), 422, 'Alcanzaste el máximo de zonas favoritas.');
        $zone = FavoriteZone::query()->create([...$data, 'user_id' => $request->user()?->id, 'anonymous_token' => $request->user() ? null : $hash]);
        return $this->response(['data' => $this->data($zone)], $plain, 201);
    }

    public function update(Request $request, FavoriteZone $favoriteZone): JsonResponse
    {
        [$hash, $plain] = $this->identity($request); $this->authorizeZone($request, $favoriteZone, $hash); $favoriteZone->update($this->validated($request, true));
        return $this->response(['data' => $this->data($favoriteZone->fresh())], $plain);
    }

    public function destroy(Request $request, FavoriteZone $favoriteZone): JsonResponse
    {
        [$hash] = $this->identity($request); $this->authorizeZone($request, $favoriteZone, $hash); $favoriteZone->delete(); return response()->json([], 204);
    }

    public function status(Request $request, FavoriteZone $favoriteZone, IncidentQueryService $query, GeoDistanceService $distance): JsonResponse
    {
        [$hash, $plain] = $this->identity($request); $this->authorizeZone($request, $favoriteZone, $hash); $categories = $this->categories($favoriteZone);
        $incidents = $query->apply(Incident::query(), ['period' => 'now', 'categories' => $categories, 'near_lat' => $favoriteZone->latitude, 'near_lng' => $favoriteZone->longitude, 'radius_km' => $favoriteZone->radius_km])->with('category:id,slug,name,icon,color')->get();
        $nearest = $incidents->map(fn ($incident) => $distance->kilometers($favoriteZone->latitude, $favoriteZone->longitude, $incident->latitude, $incident->longitude))->min();
        $count = $incidents->count(); $activity = match (true) { $count === 0 => 'SIN ACTIVIDAD', $count <= 2 => 'ACTIVIDAD BAJA', $count <= 6 => 'ACTIVIDAD MEDIA', default => 'ACTIVIDAD ALTA' };
        $groups = $incidents->groupBy('category.slug')->map(fn ($items) => ['slug' => $items->first()->category->slug, 'name' => $items->first()->category->name, 'icon' => $items->first()->category->icon, 'count' => $items->count()])->values();
        return $this->response(['data' => ['zone' => $this->data($favoriteZone), 'incident_count' => $count, 'activity' => $activity, 'nearest_distance_km' => $nearest !== null ? round($nearest, 1) : null, 'categories' => $groups, 'refreshed_at' => now()->toIso8601String()]], $plain);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes' : 'required'; $maxRadius = (float) config('alertapy.favorites.max_radius_km', 25);
        return $request->validate(['name' => [$sometimes, 'string', 'min:1', 'max:80'], 'icon' => ['sometimes', 'string', 'in:home,work,family,favorite'], 'latitude' => [$sometimes, 'numeric', 'between:-27.7,-19.1'], 'longitude' => [$sometimes, 'numeric', 'between:-62.7,-54.2'], 'radius_km' => [$sometimes, 'numeric', 'min:0.5', 'max:'.$maxRadius], 'electricity_enabled' => ['sometimes', 'boolean'], 'water_enabled' => ['sometimes', 'boolean'], 'security_enabled' => ['sometimes', 'boolean'], 'traffic_enabled' => ['sometimes', 'boolean'], 'fire_enabled' => ['sometimes', 'boolean'], 'weather_enabled' => ['sometimes', 'boolean'], 'flood_enabled' => ['sometimes', 'boolean'], 'health_enabled' => ['sometimes', 'boolean'], 'enabled' => ['sometimes', 'boolean']]);
    }

    private function identity(Request $request): array
    {
        if ($request->user()) return [null, null]; $plain = (string) ($request->cookie(self::COOKIE) ?: $request->header('X-AlertaPY-Favorite-Token')); if (! preg_match('/^[A-Za-z0-9_-]{43}$/', $plain)) $plain = Str::random(43); return [hash('sha256', $plain), $plain];
    }
    private function owned(Request $request, ?string $hash) { return FavoriteZone::query()->when($request->user(), fn ($q, $user) => $q->where('user_id', $user->id), fn ($q) => $q->whereNull('user_id')->where('anonymous_token', $hash)); }
    private function authorizeZone(Request $request, FavoriteZone $zone, ?string $hash): void { abort_unless($request->user() ? $zone->user_id === $request->user()->id : ($zone->user_id === null && hash_equals((string) $zone->anonymous_token, (string) $hash)), 404); }
    private function response(array $payload, ?string $plain, int $status = 200): JsonResponse { $response = response()->json($payload, $status); if ($plain) $response->headers->setCookie(Cookie::create(self::COOKIE, $plain)->withExpires(strtotime('+2 years'))->withPath('/')->withHttpOnly()->withSecure(request()->isSecure())->withSameSite('lax')); return $response; }
    private function categories(FavoriteZone $zone): array { $map = ['electricity_enabled' => ['electricity'], 'water_enabled' => ['water'], 'security_enabled' => ['security'], 'traffic_enabled' => ['traffic_accident', 'roads', 'borders'], 'fire_enabled' => ['fire'], 'weather_enabled' => ['weather_flood'], 'flood_enabled' => ['weather_flood', 'hydrology'], 'health_enabled' => ['health_public', 'health_vector', 'animal_health']]; return collect($map)->filter(fn ($slugs, $flag) => $zone->{$flag})->flatten()->unique()->values()->all(); }
    private function data(FavoriteZone $zone): array { return collect($zone->toArray())->except(['user_id'])->all(); }
}

