<?php

namespace App\Http\Controllers\Api;

use App\Enums\IncidentStatus;
use App\Enums\IncidentEventType;
use App\Enums\IncidentGeometryType;
use App\Enums\IncidentScope;
use App\Enums\LifecycleStatus;
use App\Enums\LocationAccuracy;
use App\Enums\LocationMethod;
use App\Enums\LocationQuality;
use App\Enums\Severity;
use App\Enums\SourceType;
use App\Enums\TemporalAccuracy;
use App\Services\IncidentSubcategoryClassifier;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommunityIncidentRequest;
use App\Http\Requests\VoteCommunityIncidentRequest;
use App\Http\Resources\IncidentSummaryResource;
use App\Models\Category;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class CommunityIncidentController extends Controller
{
    private const NEGATIVE_VOTE_THRESHOLD = 5;

    public function store(StoreCommunityIncidentRequest $request, IncidentSubcategoryClassifier $subcategories): JsonResponse
    {
        $validated = $request->validated();
        $photoPath = null;

        if ($request->hasFile('photo')) {
            $stored = $request->file('photo')?->store('community-reports', 'local');

            if (! is_string($stored) || $stored === '') {
                throw ValidationException::withMessages([
                    'photo' => 'No fue posible guardar la foto. Intentá nuevamente.',
                ]);
            }

            $photoPath = $stored;
        }

        try {
            $category = Category::query()
                ->where('slug', $validated['category_slug'])
                ->where('enabled', true)
                ->firstOrFail();
            $latitude = (float) $validated['latitude'];
            $longitude = (float) $validated['longitude'];
            $nearest = $this->nearestLocation($latitude, $longitude);
            $city = $nearest?->city;
            $department = $nearest?->department;
            $observedAt = now();

            if ($city === null && $nearest?->type === 'city') {
                $city = $nearest->name;
            }

            if ($department === null && $nearest?->type === 'department') {
                $department = $nearest->name;
            }

            $incident = Incident::query()->create([
                'category_id' => $category->id,
                'title' => $validated['title'],
                'summary' => $validated['summary'],
                'subcategory' => $subcategories->classify($category->slug, $validated['title'].' '.$validated['summary']),
                'source_type' => SourceType::Community,
                'verification_level' => 'unconfirmed',
                'status' => IncidentStatus::Reported,
                'lifecycle_status' => LifecycleStatus::Detected,
                'severity' => Severity::Medium,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'location_accuracy' => LocationAccuracy::Exact,
                'location_method' => LocationMethod::SourceCoordinates,
                'location_quality' => LocationQuality::High,
                'location_quality_flags' => [],
                'geometry_type' => IncidentGeometryType::Point,
                'incident_scope' => IncidentScope::Local,
                'department' => $department,
                'city' => $city,
                'location_text' => $validated['location_text'],
                'community_link' => $validated['source_link'] ?? null,
                'community_photo_path' => $photoPath,
                'starts_at' => $observedAt,
                'occurred_at' => $observedAt,
                'occurred_at_accuracy' => TemporalAccuracy::Exact,
                'temporal_evidence_json' => ['method'=>'COMMUNITY_SUBMISSION_TIME'],
                'detected_at' => $observedAt,
                'first_seen_at' => $observedAt,
                'last_seen_at' => $observedAt,
                'expires_at' => $observedAt->copy()->addDays(7),
                'published_at' => $observedAt,
            ]);
            IncidentUpdate::query()->create([
                'incident_id'=>$incident->id,
                'title'=>'Reporte comunitario recibido',
                'summary'=>'El reporte fue publicado como información comunitaria sin confirmación oficial.',
                'status'=>IncidentStatus::Reported,
                'event_type'=>IncidentEventType::Detected,
                'occurred_at'=>$observedAt,
                'metadata_json'=>['method'=>'COMMUNITY_SUBMISSION'],
            ]);
        } catch (Throwable $exception) {
            if ($photoPath !== null) {
                Storage::disk('local')->delete($photoPath);
            }

            throw $exception;
        }

        return (new IncidentSummaryResource($incident->load(['category', 'sources'])))
            ->response()
            ->setStatusCode(201);
    }

    public function photo(string $publicId): BinaryFileResponse
    {
        $incident = Incident::query()
            ->published()
            ->where('public_id', $publicId)
            ->where('source_type', SourceType::Community)
            ->firstOrFail();
        $path = $incident->community_photo_path;

        abort_if(! is_string($path) || ! Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'public, max-age=86400',
            'Content-Type' => (string) Storage::disk('local')->mimeType($path),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function vote(VoteCommunityIncidentRequest $request, string $publicId): JsonResponse
    {
        $validated = $request->validated();
        $result = DB::transaction(function () use ($validated, $publicId): array {
            $incident = Incident::query()
                ->published()
                ->where('public_id', $publicId)
                ->where('source_type', SourceType::Community)
                ->lockForUpdate()
                ->firstOrFail();

            if ($incident->status === IncidentStatus::Cancelled) {
                return $this->voteResult($incident, (string) $validated['vote'], false);
            }

            $incident->communityVotes()->updateOrCreate(
                ['voter_hash' => hash_hmac('sha256', (string) $validated['voter_token'], (string) config('app.key'))],
                ['vote' => $validated['vote']],
            );

            $negativeVotes = $incident->communityVotes()->where('vote', 'negative')->count();
            $removed = $negativeVotes >= self::NEGATIVE_VOTE_THRESHOLD;

            if ($removed) {
                $incident->forceFill([
                    'status' => IncidentStatus::Cancelled,
                    'lifecycle_status' => LifecycleStatus::Discarded,
                    'expires_at' => now(),
                    'expired_at' => now(),
                    'finalized_at' => now(),
                ])->save();
                IncidentUpdate::query()->create([
                    'incident_id' => $incident->id,
                    'title' => 'Reporte dado de baja por validación comunitaria',
                    'summary' => 'El reporte alcanzó 5 votos negativos. Se retiró del mapa activo, pero permanece en el historial.',
                    'status' => IncidentStatus::Cancelled,
                    'event_type' => IncidentEventType::Discarded,
                    'occurred_at' => now(),
                    'metadata_json' => ['method'=>'COMMUNITY_NEGATIVE_THRESHOLD','threshold'=>self::NEGATIVE_VOTE_THRESHOLD],
                ]);
            }

            return $this->voteResult($incident->fresh(), (string) $validated['vote'], $removed);
        });

        return response()->json(['data' => $result]);
    }

    /** @return array{positive: int, negative: int, threshold: int, vote: string, status: string, removed: bool, accepting_votes: bool} */
    private function voteResult(Incident $incident, string $vote, bool $removed): array
    {
        return [
            'positive' => $incident->communityVotes()->where('vote', 'positive')->count(),
            'negative' => $incident->communityVotes()->where('vote', 'negative')->count(),
            'threshold' => self::NEGATIVE_VOTE_THRESHOLD,
            'vote' => $vote,
            'status' => $incident->status->value,
            'removed' => $removed,
            'accepting_votes' => $incident->status !== IncidentStatus::Cancelled,
        ];
    }

    private function nearestLocation(float $latitude, float $longitude): ?Location
    {
        $nearest = Location::query()
            ->where('enabled', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->sortBy(fn (Location $location): float => $this->distanceInKilometers(
                $latitude,
                $longitude,
                (float) $location->latitude,
                (float) $location->longitude,
            ))
            ->first();

        if ($nearest === null) {
            return null;
        }

        $distance = $this->distanceInKilometers(
            $latitude,
            $longitude,
            (float) $nearest->latitude,
            (float) $nearest->longitude,
        );

        return $distance <= 50 ? $nearest : null;
    }

    private function distanceInKilometers(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
    {
        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($fromLatitude)) * cos(deg2rad($toLatitude)) * sin($longitudeDelta / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
