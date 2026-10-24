<?php

namespace App\Services;

use App\Contracts\DecisionProviderInterface;
use App\Contracts\Collectors\CollectorInterface;
use App\DTOs\CandidateDTO;
use App\DTOs\GeocodingResult;
use App\Enums\IncidentStatus;
use App\Enums\IncidentEventType;
use App\Enums\IncidentGeometryType;
use App\Enums\IncidentScope;
use App\Enums\LifecycleStatus;
use App\Enums\LocationAccuracy;
use App\Enums\Severity;
use App\Enums\SourceType;
use App\Enums\SourceRole;
use App\Models\Category;
use App\Models\ContentCandidate;
use App\Models\Incident;
use App\Models\IncidentSource;
use App\Models\IncidentUpdate;
use App\Models\RawItem;
use App\Models\Source;
use App\Models\SourceRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class ScrapedIncidentIngestor
{
    public function __construct(
        private readonly GeocodingService $geocoding,
        private readonly CorroborationService $corroboration,
        private readonly LocationResolutionPipeline $locationPipeline,
        private readonly DecisionProviderInterface $decisions,
        private readonly ClosingRuleEngine $closingRules,
        private readonly ParserStructureMonitor $structureMonitor,
        private readonly IncidentTemporalService $temporal,
        private readonly IncidentSubcategoryClassifier $subcategories,
        private readonly LocationQualityAnalyzer $locationQuality,
        private readonly PublicationExplanationService $publicationExplanation,
        private readonly IncidentDataQualityService $dataQuality,
        private readonly MapLocationFallback $mapLocationFallback,
    ) {}

    /** @return array{discovered: int, published: int, merged: int, waiting_location: int, pending_decision: int, unchanged: int, ignored_date: int} */
    public function ingest(Source $source, CollectorInterface $collector): array
    {
        $startedAt = now();
        $run = SourceRun::query()->create([
            'source_id' => $source->id,
            'started_at' => $startedAt,
            'status' => 'running',
        ]);
        $source->forceFill(['last_run_at' => $startedAt])->save();
        $stats = ['discovered' => 0, 'published' => 0, 'merged' => 0, 'waiting_location' => 0, 'pending_decision' => 0, 'unchanged' => 0, 'ignored_date' => 0];

        try {
            $candidates = $collector->collect($source);
            $stats['discovered'] = $candidates->count();
            $this->structureMonitor->observe($source, $candidates->map(fn (CandidateDTO $item) => $item->title)->implode("\n"), $candidates->count(), get_class($collector));

            foreach ($candidates as $candidate) {
                $result = $this->process($source, $candidate);
                $stats[$result]++;
            }

            $finishedAt = now();
            $run->forceFill([
                'finished_at' => $finishedAt,
                'status' => 'success',
                'discovered' => $stats['discovered'],
                'created_candidates' => $stats['published'] + $stats['merged'],
                'duration_ms' => $startedAt->diffInMilliseconds($finishedAt),
            ])->save();
            $source->forceFill([
                'last_success_at' => $finishedAt,
                'last_change_at' => ($stats['published'] + $stats['merged']) > 0
                    ? $finishedAt
                    : $source->last_change_at,
                'last_error' => null,
                'last_error_type' => null,
                'last_duration_ms' => $startedAt->diffInMilliseconds($finishedAt),
                'items_seen' => $stats['discovered'],
                'items_new' => $stats['published'] + $stats['merged'],
                'last_new_item_at' => ($stats['published'] + $stats['merged']) > 0 ? $finishedAt : $source->last_new_item_at,
                'consecutive_errors' => 0,
            ])->save();

            return $stats;
        } catch (Throwable $exception) {
            $finishedAt = now();
            $message = Str::limit($exception->getMessage(), 1900, '…');
            $run->forceFill([
                'finished_at' => $finishedAt,
                'status' => 'error',
                'discovered' => $stats['discovered'],
                'created_candidates' => $stats['published'] + $stats['merged'],
                'duration_ms' => $startedAt->diffInMilliseconds($finishedAt),
                'error' => $message,
            ])->save();
            $source->forceFill([
                'last_error' => $message,
                'last_error_type' => $this->errorType($exception),
                'last_duration_ms' => $startedAt->diffInMilliseconds($finishedAt),
                'items_seen' => $stats['discovered'],
                'items_new' => $stats['published'] + $stats['merged'],
                'consecutive_errors' => min(65535, $source->consecutive_errors + 1),
            ])->save();

            throw $exception;
        }
    }

    private function process(Source $source, CandidateDTO $candidate): string
    {
        if ($candidate->sourceSlug !== $source->slug || $candidate->sourceType !== $source->type) {
            throw new InvalidArgumentException('El candidato no coincide con la fuente que lo recolectó.');
        }

        if (! $this->isAllowedSourceUrl($source, $candidate->originalUrl)) {
            throw new InvalidArgumentException("La URL no pertenece al dominio permitido de {$source->name}.");
        }

        $explicitStartsAt = $this->date($candidate->metadata['starts_at'] ?? null);
        $temporal = $this->temporal->resolve($candidate->title.' '.$candidate->content, $candidate->publishedAt, $candidate->metadata);
        $eventAt = $temporal['occurred_at'] ?? $explicitStartsAt ?? $candidate->publishedAt;
        $cutoff = app(DataRetentionService::class)->cutoff();
        // A fresh scrape is not a fresh publication. Undated news cannot be
        // safely retained/reimported; dated official schedules remain valid.
        if ($eventAt === null || $eventAt->lt($cutoff)
            || $candidate->publishedAt?->lt($cutoff)
            || $candidate->publishedAt?->isFuture()) {
            return 'ignored_date';
        }

        [$rawItem, $rawChanged] = $this->storeRawItem($source, $candidate);
        $existingCandidate = ContentCandidate::query()
            ->where('source_id', $source->id)
            ->where('raw_item_id', $rawItem->id)
            ->first();

        if (! $rawChanged && $existingCandidate?->status === 'published' && $rawItem->processed_at !== null) {
            return 'unchanged';
        }

        $isLocationFallback = (bool) ($candidate->metadata['location_is_fallback'] ?? false);
        $roadResolution = $isLocationFallback ? null : $this->locationPipeline->resolve(
            $candidate->title.' '.$candidate->content,
            $candidate->latitude,
            $candidate->longitude,
            $this->nullableString($candidate->metadata['city'] ?? null),
            $this->nullableString($candidate->metadata['district'] ?? null),
            $this->nullableString($candidate->metadata['department'] ?? null),
        );
        $location = $this->nativeLocation($candidate)
            ?? ($roadResolution !== null ? $this->locationPipeline->geocodingResult($roadResolution) : null)
            ?? ($candidate->detectedLocation !== null
                ? $this->geocoding->resolve($candidate->detectedLocation)
                : null);
        if ($location === null && ! $this->mapLocationFallback->isClearlyEditorial($candidate->title, $candidate->content)) {
            $fallback = $this->mapLocationFallback->forNews($candidate->sourceType, null);
            if ($fallback !== null) {
                $location = new GeocodingResult(
                    $fallback['latitude'],
                    $fallback['longitude'],
                    LocationAccuracy::Approximate,
                    'map-reference',
                    $fallback['query'],
                );
                $isLocationFallback = true;
                $candidate = new CandidateDTO(
                    sourceSlug: $candidate->sourceSlug,
                    sourceType: $candidate->sourceType,
                    externalIdentifier: $candidate->externalIdentifier,
                    title: $candidate->title,
                    originalUrl: $candidate->originalUrl,
                    content: $candidate->content,
                    publishedAt: $candidate->publishedAt,
                    detectedLocation: $candidate->detectedLocation,
                    latitude: $candidate->latitude,
                    longitude: $candidate->longitude,
                    metadata: [
                        ...$candidate->metadata,
                        'location_is_fallback' => true,
                        'location_text' => MapLocationFallback::LABEL,
                        'location_accuracy' => 'approximate',
                        'city' => null,
                        'department' => null,
                    ],
                );
            }
        }
        $locationEvidence = $isLocationFallback
            ? ['method' => MapLocationFallback::METHOD, 'reported_location' => null, 'map_reference' => 'Asunción']
            : $roadResolution?->evidence;
        $contentCandidate = ContentCandidate::query()->updateOrCreate(
            ['source_id' => $source->id, 'raw_item_id' => $rawItem->id],
            [
                'source_type' => $source->type,
                'status' => $location === null ? 'waiting_location' : 'processing',
                'normalized_content' => $this->summary($candidate),
                'detected_location' => $candidate->detectedLocation,
                'road_code' => $roadResolution?->roadCode,
                'road_kilometer' => $roadResolution?->kilometer,
                'location_evidence_json' => $locationEvidence,
                'latitude' => $location?->latitude,
                'longitude' => $location?->longitude,
                'attempts' => 0,
                'next_retry_at' => $location === null ? now()->addMinutes(30) : null,
            ],
        );

        if ($location === null) {
            return 'waiting_location';
        }

        $category = Category::query()
            ->where('slug', (string) ($candidate->metadata['category_slug'] ?? ''))
            ->where('enabled', true)
            ->first();

        if ($category === null) {
            $contentCandidate->forceFill(['status' => 'rejected_category', 'processed_at' => now()])->save();

            return 'waiting_location';
        }

        $decision = $this->decisions->decide($source, $candidate, $location);
        $contentCandidate->forceFill([
            'ai_status' => 'completed',
            'ai_decision' => $decision->action,
            'ai_confidence' => $decision->evidenceScore,
            'ai_reason' => $decision->reason,
            'ai_model' => 'rule-based-v1',
            'evidence_score' => $decision->evidenceScore,
            'evidence_components_json' => $decision->scoreComponents,
        ])->save();

        if (in_array($decision->action, ['WAIT', 'REJECT'], true)) {
            $contentCandidate->forceFill(['status' => $decision->action === 'REJECT' ? 'rejected_rules' : 'pending_ai', 'processed_at' => $decision->action === 'REJECT' ? now() : null, 'next_retry_at' => $decision->action === 'WAIT' ? now()->addMinutes(30) : null])->save();
            return 'pending_decision';
        }

        return DB::transaction(function () use ($source, $candidate, $rawItem, $rawChanged, $contentCandidate, $category, $location, $roadResolution, $decision, $explicitStartsAt, $temporal, $isLocationFallback, $locationEvidence): string {
            $startsAt = $explicitStartsAt
                ?? $temporal['occurred_at']
                ?? $candidate->publishedAt
                ?? CarbonImmutable::now();
            $endsAt = $this->date($candidate->metadata['ends_at'] ?? null);
            $locationText = $isLocationFallback
                ? MapLocationFallback::LABEL
                : trim((string) ($candidate->metadata['location_text'] ?? $candidate->detectedLocation));
            $city = $isLocationFallback ? null : $this->nullableString($candidate->metadata['city'] ?? null);
            $department = $isLocationFallback ? null : $this->nullableString($candidate->metadata['department'] ?? null);
            $sourceAccuracy = $isLocationFallback
                ? LocationAccuracy::Approximate
                : LocationAccuracy::tryFrom((string) ($candidate->metadata['location_accuracy'] ?? ''));
            $incident = ! $isLocationFallback && $decision->action === 'CLOSE'
                ? $this->closingRules->findMatching($category->id, $city, $department, $startsAt, $candidate->title.' '.$candidate->content, $candidate, $rawItem->id)
                : null;
            if (! $isLocationFallback && $decision->action === 'CLOSE' && $incident === null) {
                $contentCandidate->forceFill(['status' => 'pending_ai', 'next_retry_at' => now()->addMinutes(30)])->save();
                return 'pending_decision';
            }
            $incident ??= Incident::query()
                ->whereHas('sources', fn ($query) => $query->where('raw_item_id', $rawItem->id))
                ->first();
            $incident ??= ($isLocationFallback ? null : ($roadResolution?->roadId !== null ? $this->findRoadDuplicate($category->id, $roadResolution->roadId, $roadResolution->kilometer, $startsAt, $candidate) : null))
            ?? ($isLocationFallback ? null : $this->findCorroboratedFire(
                $source,
                $category->slug,
                $location->latitude,
                $location->longitude,
                $startsAt,
            )) ?? ($isLocationFallback ? null : $this->findPotentialDuplicate(
                $category->id,
                $category->slug,
                $locationText,
                $startsAt,
                $candidate->sourceType,
                max(1, (int) ($candidate->metadata['dedup_window_hours'] ?? 12)),
                ($candidate->metadata['dedup_source_only'] ?? false) ? $source->id : null,
                $city,
                $department,
                $candidate,
            )) ?? ($isLocationFallback ? null : $this->corroboration->findMatch(
                $category->id,
                $candidate,
                $location,
                $startsAt,
                $city,
                $department,
                $source,
            ));
            $wasMerged = $incident !== null;

            if ($incident === null) {
                $representationType = $this->representationType($candidate);
                $incident = Incident::query()->create([
                    'category_id' => $category->id,
                    'title' => Str::limit($candidate->title, 220, '…'),
                    'summary' => $this->summary($candidate),
                    'subcategory' => $this->subcategories->classify($category->slug, $candidate->title.' '.$candidate->content),
                    'source_type' => $candidate->sourceType,
                    'verification_level' => in_array($candidate->sourceType, [SourceType::Official, SourceType::Sensor], true)
                        ? 'official'
                        : 'press',
                    'status' => match ($candidate->sourceType) {
                        SourceType::Official => IncidentStatus::Scheduled,
                        SourceType::Sensor => IncidentStatus::Active,
                        SourceType::Press => in_array($category->slug, ['electricity', 'water'], true) && $explicitStartsAt?->isFuture()
                            ? IncidentStatus::Scheduled
                            : IncidentStatus::Reported,
                        default => IncidentStatus::Reported,
                    },
                    'lifecycle_status' => in_array($candidate->sourceType, [SourceType::Official, SourceType::Sensor], true) ? LifecycleStatus::Active : LifecycleStatus::Detected,
                    'border_status' => $category->slug === 'borders'
                        ? $this->nullableString($candidate->metadata['border_status'] ?? null)
                        : null,
                    'representation_type' => $representationType,
                    'geometry_type' => $this->geometryType($representationType, $roadResolution?->roadId),
                    'geometry_geojson' => $this->geometry($candidate),
                    'road_id' => $roadResolution?->roadId,
                    'road_code' => $roadResolution?->roadCode,
                    'road_kilometer' => $roadResolution?->kilometer,
                    'road_location_method' => $roadResolution?->resolutionMethod,
                    'location_error_meters' => $roadResolution?->estimatedErrorMeters,
                    'location_evidence_json' => $locationEvidence,
                    'severity' => $this->severity($candidate),
                    'latitude' => $location->latitude,
                    'longitude' => $location->longitude,
                    'location_accuracy' => $sourceAccuracy ?? $location->precision,
                    'department' => $department,
                    'city' => $city,
                    'location_text' => $locationText,
                    'incident_scope' => $this->scope($candidate, $roadResolution?->roadId, $city, $department),
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'expires_at' => $endsAt ?? IncidentValidityService::expiresAt($startsAt, $category->slug),
                    'occurred_at' => $temporal['occurred_at'],
                    'occurred_at_accuracy' => $temporal['accuracy'],
                    'temporal_evidence_json' => $temporal['evidence'],
                    'detected_at' => $rawItem->detected_at,
                    'first_seen_at' => $rawItem->detected_at,
                    'last_seen_at' => $rawItem->detected_at,
                    'published_at' => $candidate->publishedAt ?? now(),
                ]);
                $quality = $this->locationQuality->analyze($incident->loadMissing('road'));
                $incident->forceFill(['location_method'=>$quality['method'],'location_quality'=>$quality['quality'],'location_quality_flags'=>$quality['flags']])->save();
            }

            $sourceReference = IncidentSource::query()->firstOrCreate(
                ['incident_id' => $incident->id, 'raw_item_id' => $rawItem->id],
                [
                    'source_id' => $source->id,
                    'original_title' => Str::limit((string) ($candidate->metadata['original_title'] ?? $candidate->title), 500, '…'),
                    'original_url' => $candidate->originalUrl,
                    'published_at' => $candidate->publishedAt,
                    'detected_at' => $rawItem->detected_at,
                    'source_role' => $wasMerged ? SourceRole::Corroborating : SourceRole::Primary,
                ],
            );

            $this->corroboration->applyEvidence($incident);

            if ($decision->action === 'CLOSE') {
                $incident->forceFill(['status' => IncidentStatus::Finalized, 'lifecycle_status'=>LifecycleStatus::Resolved, 'resolved_at'=>$candidate->publishedAt ?? now(), 'finalized_at' => $candidate->publishedAt ?? now(), 'expires_at' => $candidate->publishedAt ?? now()])->save();
            }

            if ($wasMerged && ($sourceReference->wasRecentlyCreated || $rawChanged)) {
                if ($category->slug === 'borders') {
                    $borderStatus = $this->nullableString($candidate->metadata['border_status'] ?? null);

                    if ($borderStatus !== null && $incident->border_status !== $borderStatus) {
                        $incident->forceFill(['border_status' => $borderStatus])->save();
                    }
                }

                $this->mergeOfficialElectricitySchedule(
                    $incident,
                    $candidate,
                    $category->slug,
                    $startsAt,
                    $endsAt,
                    $city,
                    $department,
                );
                IncidentUpdate::query()->create([
                    'incident_id' => $incident->id,
                    'source_id' => $source->id,
                    'raw_item_id' => $rawItem->id,
                    'title' => Str::limit($candidate->title, 220, '…'),
                    'summary' => $this->summary($candidate),
                    'status' => $incident->status,
                    'event_type' => $decision->action === 'CLOSE' ? IncidentEventType::Resolved : IncidentEventType::Updated,
                    'occurred_at' => $candidate->publishedAt ?? now(),
                    'metadata_json' => ['source_role'=>$sourceReference->source_role->value],
                ]);
            }

            if (! $wasMerged) {
                IncidentUpdate::query()->create(['incident_id'=>$incident->id,'source_id'=>$source->id,'raw_item_id'=>$rawItem->id,'title'=>'Incidencia detectada','summary'=>$this->summary($candidate),'status'=>$incident->status,'event_type'=>IncidentEventType::Detected,'occurred_at'=>$rawItem->detected_at,'metadata_json'=>['method'=>'INGESTION']]);
            }

            $incident->forceFill([
                'last_seen_at' => $rawItem->detected_at,
                'publication_explanation_json' => $this->publicationExplanation->forIncident($incident),
                'data_quality_json' => $this->dataQuality->forIncident($incident->loadMissing(['category','sources'])),
            ])->save();

            $rawItem->forceFill(['processed_at' => now()])->save();
            $contentCandidate->forceFill([
                'status' => 'published',
                'processed_at' => now(),
                'next_retry_at' => null,
            ])->save();

            return $wasMerged ? 'merged' : 'published';
        });
    }

    /** @return array{RawItem, bool} */
    private function storeRawItem(Source $source, CandidateDTO $candidate): array
    {
        $externalIdentifier = $candidate->externalIdentifier
            ?? hash('sha256', $candidate->originalUrl.'|'.$candidate->title);
        $contentHash = hash('sha256', $candidate->title."\n".$candidate->content."\n".$candidate->originalUrl
            .((bool) ($candidate->metadata['category_variant'] ?? false)
                ? "\n".(string) ($candidate->metadata['category_slug'] ?? '')
                : ''));
        $rawItem = RawItem::query()
            ->where('source_id', $source->id)
            ->where(function ($query) use ($externalIdentifier, $contentHash): void {
                $query->where('external_identifier', $externalIdentifier)
                    ->orWhere('content_hash', $contentHash);
            })
            ->first() ?? new RawItem(['source_id' => $source->id]);
        $changed = ! $rawItem->exists || $rawItem->content_hash !== $contentHash;

        $rawItem->fill([
            'external_identifier' => $externalIdentifier,
            'original_url' => $candidate->originalUrl,
            'title' => Str::limit((string) ($candidate->metadata['original_title'] ?? $candidate->title), 500, '…'),
            'raw_content' => $candidate->content,
            'content_hash' => $contentHash,
            'published_at' => $candidate->publishedAt,
            'detected_at' => $rawItem->detected_at ?? now(),
        ])->save();

        return [$rawItem, $changed];
    }

    private function findPotentialDuplicate(
        int $categoryId,
        string $categorySlug,
        string $locationText,
        CarbonImmutable $startsAt,
        SourceType $sourceType,
        int $dedupWindowHours,
        ?int $dedupSourceId,
        ?string $city,
        ?string $department,
        CandidateDTO $candidate,
    ): ?Incident {
        $query = Incident::query()
            ->where('category_id', $categoryId)
            ->where('status', '!=', IncidentStatus::Cancelled)
            ->where('source_type', $sourceType);

        if ($dedupSourceId !== null) {
            $query->whereHas('sources', fn ($reference) => $reference->where('source_id', $dedupSourceId));
        }

        if ($categorySlug === 'electricity' && $sourceType === SourceType::Official) {
            $query->whereDate('starts_at', $startsAt->toDateString());

            if ($city !== null) {
                $query->where('city', $city);
            } elseif ($department !== null) {
                $query->whereNull('city')->where('department', $department);
            } else {
                $query->where('location_text', $locationText);
            }
        } else {
            $query
                ->whereBetween('starts_at', [$startsAt->subHours($dedupWindowHours), $startsAt->addHours($dedupWindowHours)])
                ->where('location_text', $locationText);
        }

        if ($categorySlug === 'electricity' && $sourceType === SourceType::Official) {
            return $query->orderBy('starts_at')->first();
        }

        return $query->orderByDesc('starts_at')->get()
            ->first(fn (Incident $incident): bool => $this->corroboration->hasMatchingEvidence($candidate, $incident));
    }

    private function findRoadDuplicate(int $categoryId, int $roadId, ?float $kilometer, CarbonImmutable $startsAt, CandidateDTO $candidate): ?Incident
    {
        if ($kilometer === null) return null;
        $tolerance = max(0.05, (float) config('alertapy.roads.duplicate_km_tolerance', 1));
        return Incident::query()->where('category_id', $categoryId)->where('road_id', $roadId)
            ->where('status', '!=', IncidentStatus::Cancelled)
            ->whereBetween('road_kilometer', [$kilometer - $tolerance, $kilometer + $tolerance])
            ->whereBetween('starts_at', [$startsAt->subHours(12), $startsAt->addHours(12)])
            ->orderByRaw('ABS(road_kilometer - ?)', [$kilometer])->get()
            ->first(fn (Incident $incident): bool => $this->corroboration->hasMatchingEvidence($candidate, $incident));
    }

    private function findCorroboratedFire(
        Source $source,
        string $categorySlug,
        float $latitude,
        float $longitude,
        CarbonImmutable $startsAt,
    ): ?Incident {
        if ($categorySlug !== 'fire' || ! in_array($source->slug, ['nasa-firms', 'infona'], true)) {
            return null;
        }

        $otherSource = $source->slug === 'nasa-firms' ? 'infona' : 'nasa-firms';
        $window = max(1, (int) config('alertapy.sources.nasa-firms.correlation_window_hours', 24));
        $distance = max(1.0, (float) config('alertapy.sources.nasa-firms.correlation_distance_km', 15));
        $incident = Incident::query()
            ->whereHas('sources.source', fn ($query) => $query->where('slug', $otherSource))
            ->whereBetween('starts_at', [$startsAt->subHours($window), $startsAt->addHours($window)])
            ->get()
            ->first(fn (Incident $candidate): bool => $this->distanceKm(
                $latitude,
                $longitude,
                (float) $candidate->latitude,
                (float) $candidate->longitude,
            ) <= $distance);

        if ($incident !== null) {
            $incident->forceFill(['verification_level' => 'corroborated'])->save();
        }

        return $incident;
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371.0;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function nativeLocation(CandidateDTO $candidate): ?GeocodingResult
    {
        if ($candidate->latitude === null || $candidate->longitude === null) {
            return null;
        }

        if ($candidate->latitude < -90 || $candidate->latitude > 90 || $candidate->longitude < -180 || $candidate->longitude > 180) {
            return null;
        }

        $query = $candidate->detectedLocation
            ?? sprintf('%.7f, %.7f', $candidate->latitude, $candidate->longitude);

        return new GeocodingResult(
            latitude: $candidate->latitude,
            longitude: $candidate->longitude,
            precision: LocationAccuracy::Exact,
            provider: 'source_native',
            query: $query,
        );
    }

    private function mergeOfficialElectricitySchedule(
        Incident $incident,
        CandidateDTO $candidate,
        string $categorySlug,
        CarbonImmutable $startsAt,
        ?CarbonImmutable $endsAt,
        ?string $city,
        ?string $department,
    ): void {
        if ($categorySlug !== 'electricity' || $candidate->sourceType !== SourceType::Official) {
            return;
        }

        $candidateSummary = $this->summary($candidate);
        $summaries = collect(preg_split('/\n{2,}/u', $incident->summary) ?: [])
            ->push($candidateSummary)
            ->map(fn (string $summary): string => trim($summary))
            ->filter()
            ->unique()
            ->values();
        $existingStart = $incident->starts_at !== null
            ? CarbonImmutable::instance($incident->starts_at)
            : null;
        $existingEnd = $incident->ends_at !== null
            ? CarbonImmutable::instance($incident->ends_at)
            : null;
        $earliestStart = $existingStart === null || $startsAt->lt($existingStart)
            ? $startsAt
            : $existingStart;
        $latestEnd = $endsAt !== null && ($existingEnd === null || $endsAt->gt($existingEnd))
            ? $endsAt
            : $existingEnd;
        $place = $city ?? $department ?? $incident->location_text;

        $incident->forceFill([
            'title' => "Trabajos programados de ANDE en {$place}",
            'summary' => $summaries->implode("\n\n"),
            'location_text' => $place,
            'starts_at' => $earliestStart,
            'ends_at' => $latestEnd,
            'expires_at' => $latestEnd ?? $incident->expires_at,
        ])->save();
    }

    private function isAllowedSourceUrl(Source $source, string $url): bool
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
        $domain = Str::lower($source->domain);

        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    private function summary(CandidateDTO $candidate): string
    {
        if ($candidate->sourceType === SourceType::Press) {
            return Str::limit($candidate->title, 700, '…');
        }

        $content = trim(preg_replace('/\s+/u', ' ', strip_tags($candidate->content)) ?: $candidate->content);

        return Str::limit($content !== '' ? $content : $candidate->title, 700, '…');
    }

    private function severity(CandidateDTO $candidate): Severity
    {
        $evidence = Str::lower(Str::ascii($candidate->title.' '.$candidate->content));

        $explicit = Severity::tryFrom(Str::lower((string) ($candidate->metadata['severity'] ?? '')));
        if ($explicit !== null) return $explicit;
        if (($candidate->metadata['cap_severity'] ?? null) === 'extreme') return Severity::Critical;

        return preg_match('/\b(fatal|muerte|muere|fallec|granizo|rafagas intensas)\w*/u', $evidence) === 1
            ? Severity::High
            : Severity::Medium;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function representationType(CandidateDTO $candidate): string
    {
        $geometry = $this->geometry($candidate);
        $requested = strtolower((string) ($candidate->metadata['representation_type'] ?? 'point'));

        if ($geometry === null || ! in_array($requested, ['line', 'polygon'], true)) {
            return 'point';
        }

        return $requested;
    }

    private function geometryType(string $representationType, ?int $roadId): IncidentGeometryType
    {
        if ($roadId !== null) return IncidentGeometryType::RouteSegment;
        return match ($representationType) {
            'line' => IncidentGeometryType::Line,
            'polygon' => IncidentGeometryType::Polygon,
            'station' => IncidentGeometryType::Station,
            default => IncidentGeometryType::Point,
        };
    }

    private function scope(CandidateDTO $candidate, ?int $roadId, ?string $city, ?string $department): IncidentScope
    {
        $explicit = IncidentScope::tryFrom(Str::lower((string) ($candidate->metadata['incident_scope'] ?? '')));
        if ($explicit !== null) return $explicit;
        if ($roadId !== null) return IncidentScope::RouteSegment;
        if (is_array($candidate->metadata['departments'] ?? null) && count($candidate->metadata['departments']) > 1) return IncidentScope::MultiDepartment;
        if ($city !== null) return IncidentScope::City;
        if ($department !== null) return IncidentScope::Department;
        return IncidentScope::Local;
    }

    private function errorType(Throwable $exception): string
    {
        $message = Str::lower(Str::ascii($exception->getMessage()));
        return match (true) {
            str_contains($message, 'radware'), str_contains($message, 'anti-bot') => 'ANTI_BOT_BLOCKED',
            str_contains($message, 'resolve host'), str_contains($message, 'dns') => 'DNS_RESOLUTION_ERROR',
            str_contains($message, 'timeout'), str_contains($message, 'timed out') => 'TIMEOUT',
            str_contains($message, 'parser'), str_contains($message, 'structure') => 'PARSER_STRUCTURE_CHANGED',
            default => 'COLLECTOR_ERROR',
        };
    }

    /** @return array<string, mixed>|null */
    private function geometry(CandidateDTO $candidate): ?array
    {
        $geometry = $candidate->metadata['geometry_geojson'] ?? null;

        if (is_string($geometry)) {
            $geometry = json_decode($geometry, true);
        }

        if (! is_array($geometry)
            || ! in_array($geometry['type'] ?? null, ['LineString', 'MultiLineString', 'Polygon', 'MultiPolygon'], true)
            || ! is_array($geometry['coordinates'] ?? null)
            || $geometry['coordinates'] === []) {
            return null;
        }

        return $geometry;
    }
}

