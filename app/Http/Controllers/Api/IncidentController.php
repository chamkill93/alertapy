<?php

namespace App\Http\Controllers\Api;

use App\Enums\IncidentEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\IncidentIndexRequest;
use App\Http\Resources\IncidentDetailResource;
use App\Http\Resources\IncidentSummaryResource;
use App\Models\Category;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Services\IncidentLifecycleService;
use App\Services\IncidentQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class IncidentController extends Controller
{
    public function __construct(private readonly IncidentQueryService $queryService) {}

    public function index(IncidentIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->filters();
        $filtered = $this->queryService->apply(Incident::query(), $filters);

        $counts = (clone $filtered)
            ->reorder()
            ->selectRaw('category_id, count(*) as aggregate_count')
            ->groupBy('category_id')
            ->pluck('aggregate_count', 'category_id');

        // Facet counts describe the other filters, before category selection or pagination.
        // This also lets an empty selection (__none__) show which layers are available.
        $availabilityFilters = $filters;
        unset($availabilityFilters['categories']);
        $availabilityCounts = empty($filters['categories']) ? $counts : $this->queryService
            ->apply(Incident::query(), $availabilityFilters)
            ->selectRaw('category_id, count(*) as aggregate_count')
            ->groupBy('category_id')
            ->pluck('aggregate_count', 'category_id');
        $categoryAvailability = Category::query()
            ->orderBy('sort_order')
            ->orderBy('slug')
            ->get(['id', 'slug'])
            ->map(fn (Category $category): array => [
                'slug' => $category->slug,
                'count' => (int) $availabilityCounts->get($category->id, 0),
            ])
            ->values();

        $summary = Category::query()
            ->whereIn('id', $counts->keys())
            ->orderBy('sort_order')
            ->get(['id', 'slug', 'name', 'icon', 'color'])
            ->map(fn (Category $category): array => [
                'slug' => $category->slug,
                'name' => $category->name,
                'icon' => $category->icon,
                'color' => $category->color,
                'count' => (int) $counts->get($category->id, 0),
            ])
            ->values();

        $filtered
            ->with([
                'category:id,slug,name,icon,color',
                'sources:id,incident_id,source_id,original_url',
            ])
            ->orderByDesc('published_at')
            ->orderByDesc('id');
        $perPage = (int) $request->input('per_page', 100);
        $incidents = ($request->input('pagination') === 'cursor'
            ? $filtered->cursorPaginate($perPage)
            : $filtered->paginate($perPage))->withQueryString();

        return IncidentSummaryResource::collection($incidents)->additional([
            'summary' => $summary,
            'category_availability' => $categoryAvailability,
            'refreshed_at' => now()->toIso8601String(),
        ]);
    }

    public function show(string $publicId): IncidentDetailResource
    {
        $incident = Incident::query()
            ->published()
            ->withinRetention()
            ->with([
                'category:id,slug,name,icon,color',
                'sources.source:id,name,type',
                'updates.source:id,name',
            ])
            ->withCount([
                'communityVotes as community_positive_votes_count' => fn ($query) => $query->where('vote', 'positive'),
                'communityVotes as community_negative_votes_count' => fn ($query) => $query->where('vote', 'negative'),
            ])
            ->where('public_id', $publicId)
            ->firstOrFail();

        return new IncidentDetailResource($incident);
    }

    public function updates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $limit = (int) ($validated['limit'] ?? 50);
        $queryLimit = min(100, max($limit * 5, $limit));
        $since = $validated['since'] ?? null;

        $updates = IncidentUpdate::query()
            ->whereHas('incident', fn ($query) => $query->published()->withinRetention())
            ->where('event_type', '!=', IncidentEventType::Expired)
            ->when($since, fn ($query, $value) => $query->where('created_at', '>', $value))
            ->with([
                'incident.category:id,slug,name,icon,color',
                'incident.sources:id,incident_id,source_id,original_url',
                'incident.sources.source:id,name',
                'source:id,name',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($queryLimit)
            ->get()
            ->map(function (IncidentUpdate $update): array {
                $isDetection = $update->event_type === IncidentEventType::Detected;

                return $this->activityItem(
                    $update->incident,
                    $isDetection ? 'new' : 'update',
                    $isDetection ? $update->incident->title : $update->title,
                    $isDetection ? $update->incident->summary : $update->summary,
                    $update->occurred_at->toIso8601String(),
                    $update->created_at->toIso8601String(),
                    $update->source?->name,
                );
            });

        $incidents = Incident::query()
            ->published()
            ->withinRetention()
            ->when($since, fn ($query, $value) => $query->where('created_at', '>', $value))
            ->with([
                'category:id,slug,name,icon,color',
                'sources:id,incident_id,source_id,original_url',
                'sources.source:id,name',
            ])
            ->orderByDesc('created_at')
            ->limit($queryLimit)
            ->get()
            ->map(fn (Incident $incident): array => $this->activityItem(
                $incident,
                'new',
                $incident->title,
                $incident->summary,
                $incident->published_at->toIso8601String(),
                $incident->created_at->toIso8601String(),
            ));

        $activity = $updates
            ->concat($incidents)
            ->sortByDesc('activity_at')
            ->unique('incident_id')
            ->values();
        $activity = $this->diverseActivity($activity, $limit);

        return response()->json([
            'data' => $activity,
            'refreshed_at' => now()->toIso8601String(),
        ]);
    }

    private function diverseActivity(Collection $activity, int $limit): Collection
    {
        $selected = collect();
        $sourceCounts = [];
        $categoryCounts = [];

        foreach ($activity as $item) {
            $sourceKey = Str::lower((string) ($item['source']['name'] ?? $item['source']['label']));
            $categoryKey = (string) $item['category']['slug'];
            $sourceMaximum = $sourceKey === 'ande' ? 2 : 5;
            $categoryMaximum = $categoryKey === 'electricity' ? 3 : 7;

            if (($sourceCounts[$sourceKey] ?? 0) >= $sourceMaximum
                || ($categoryCounts[$categoryKey] ?? 0) >= $categoryMaximum) {
                continue;
            }

            $selected->push($item);
            $sourceCounts[$sourceKey] = ($sourceCounts[$sourceKey] ?? 0) + 1;
            $categoryCounts[$categoryKey] = ($categoryCounts[$categoryKey] ?? 0) + 1;

            if ($selected->count() >= $limit) {
                break;
            }
        }

        return $selected->values();
    }

    private function activityItem(
        Incident $incident,
        string $type,
        string $title,
        string $summary,
        string $occurredAt,
        string $activityAt,
        ?string $sourceName = null,
    ): array {
        $lifecycle = app(IncidentLifecycleService::class)->derive($incident);

        return [
            'activity_type' => $type,
            'activity_at' => $activityAt,
            'incident_id' => $incident->public_id,
            'incident_title' => $incident->title,
            'title' => $title,
            'summary' => $summary,
            'category' => [
                'slug' => $incident->category->slug,
                'name' => $incident->category->name,
                'icon' => $incident->category->icon,
                'color' => $incident->category->color,
            ],
            'location' => [
                'latitude' => $incident->latitude,
                'longitude' => $incident->longitude,
                'text' => $incident->location_text,
                'city' => $incident->city,
                'department' => $incident->department,
            ],
            'source' => [
                'type' => $incident->source_type->value,
                'label' => $incident->source_type->label(),
                'icon' => $incident->source_type->icon(),
                'name' => $sourceName ?? $incident->sources->first()?->source?->name,
                'url' => $incident->sources->first()?->original_url,
                'count' => $incident->sources->unique('original_url')->count(),
            ],
            'status' => [
                'value' => $incident->status->value,
                'label' => $incident->status->label(),
            ],
            'lifecycle_status' => [
                'value' => $lifecycle->value,
                'label' => $lifecycle->label(),
            ],
            'border_status' => $incident->border_status,
            'occurred_at' => $occurredAt,
            'updated_at' => $activityAt,
        ];
    }
}
