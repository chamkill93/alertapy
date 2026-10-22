<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ContentCandidate;
use App\Models\Incident;
use App\Models\IncidentSource;
use App\Models\RawItem;
use App\Models\Source;
use App\Models\SourceRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Read-only, presentation-safe data for the private operational interface. */
class BackofficeDataService
{
    public const NEWS_STATUSES = [
        'pending' => 'Pendientes', 'published' => 'Publicadas',
        'rejected' => 'Descartadas', 'unprocessed' => 'Sin procesar',
    ];

    public function __construct(
        private readonly SourceHealthService $sourceHealth,
        private readonly SchedulerHealthService $schedulerHealth,
    ) {}

    public function dashboard(): array
    {
        $connections = $this->connectionRows();
        $pending = $this->filterNewsStatus($this->newsQuery(), 'pending')->count();

        return [
            'summary' => [
                'received_24h' => RawItem::query()->where('detected_at', '>=', now()->subDay())->count(),
                'published_24h' => Incident::query()->published()->where('published_at', '>=', now()->subDay())->count(),
                'pending' => $pending,
                'sources_enabled' => $connections->where('enabled', true)->count(),
                'sources_problematic' => $this->problematic($connections)->count(),
            ],
            'scheduler' => $this->scheduler(),
            'latestNews' => $this->newsRows($this->newsQuery()->latest('detected_at')->latest('id')->limit(6)->get())->values()->all(),
            'attentionConnections' => $this->problematic($connections)->take(6)->values()->all(),
        ];
    }

    public function newsIndex(Request $request): array
    {
        $filters = [
            'q' => $this->filterText($request, 'q', 200),
            'source' => $this->filterText($request, 'source'),
            'status' => array_key_exists($this->filterText($request, 'status'), self::NEWS_STATUSES) ? $this->filterText($request, 'status') : '',
            'category' => $this->filterText($request, 'category'),
        ];
        $query = $this->newsQuery();
        if ($filters['q'] !== '') {
            $term = $this->likeTerm($filters['q']);
            $query->where(function (Builder $query) use ($term): void {
                $query->whereRaw("title LIKE ? ESCAPE '!'", [$term])->orWhereRaw("raw_content LIKE ? ESCAPE '!'", [$term]);
            });
        }
        if ($filters['source'] !== '') {
            $query->whereHas('source', fn (Builder $source) => $source->where('slug', $filters['source']));
        }
        if ($filters['status'] !== '') {
            $this->filterNewsStatus($query, $filters['status']);
        }
        if ($filters['category'] !== '') {
            $query->whereIn('id', IncidentSource::query()->select('raw_item_id')->whereHas('incident.category', fn (Builder $category) => $category->where('slug', $filters['category'])));
        }
        $news = $query->latest('detected_at')->latest('id')->paginate(25)->withQueryString();
        $news->setCollection($this->newsRows($news->getCollection()));

        return [
            'news' => $news, 'filters' => $filters,
            'sources' => Source::query()->orderBy('name')->get(['id', 'name', 'slug'])->toArray(),
            'categories' => Category::query()->orderBy('sort_order')->get(['id', 'name', 'slug'])->toArray(),
            'statusOptions' => self::NEWS_STATUSES,
        ];
    }

    public function newsDetail(RawItem $rawItem): array
    {
        $rawItem->load(['source', 'candidates' => fn ($query) => $query->orderByDesc('id')]);
        $row = $this->newsRows(collect([$rawItem]))->first();

        return [
            ...$row,
            'content' => $this->plainText($rawItem->raw_content),
            'decisions' => $rawItem->candidates->map(fn (ContentCandidate $candidate): array => [
                'status' => $candidate->status,
                'status_label' => $this->candidateStatusLabel($candidate->status),
                'decision' => $candidate->ai_decision,
                'reason' => $this->safeExplanation($candidate->ai_reason),
                'location' => $this->plainText($candidate->detected_location),
                'processed_at' => $candidate->processed_at?->toIso8601String(),
                'next_retry_at' => $candidate->next_retry_at?->toIso8601String(),
                'attempts' => $candidate->attempts,
            ])->all(),
        ];
    }

    public function connectionsIndex(Request $request): array
    {
        $all = $this->connectionRows();
        $filters = [
            'q' => $this->filterText($request, 'q', 200),
            'health' => $this->filterText($request, 'health', 40),
            'enabled' => in_array($request->query('enabled'), ['1', '0'], true) ? $request->query('enabled') : '',
        ];
        $filtered = $all->filter(function (array $row) use ($filters): bool {
            return ($filters['q'] === '' || Str::contains(Str::lower($row['name'].' '.$row['slug'].' '.$row['domain']), Str::lower($filters['q'])))
                && ($filters['health'] === '' || $row['health'] === $filters['health'])
                && ($filters['enabled'] === '' || $row['enabled'] === ($filters['enabled'] === '1'));
        })->values();
        $page = max(1, (int) $this->filterText($request, 'page'));
        $connections = new LengthAwarePaginator($filtered->forPage($page, 25)->values(), $filtered->count(), 25, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);

        return [
            'connections' => $connections, 'filters' => $filters,
            'summary' => [
                'total' => $all->count(), 'enabled' => $all->where('enabled', true)->count(),
                'healthy' => $all->where('health', 'HEALTHY')->count(),
                'problematic' => $this->problematic($all)->count(),
                'disabled' => $all->where('enabled', false)->count(),
            ],
            'scheduler' => $this->scheduler(),
        ];
    }

    public function connectionDetail(Source $source, Request $request): array
    {
        $source = $this->sourceQuery()->findOrFail($source->id);
        $runs = $source->runs()->latest('started_at')->latest('id')->paginate(20)->withQueryString();
        $runs->setCollection($runs->getCollection()->map(fn (SourceRun $run): array => [
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'status' => $run->status,
            'status_label' => match ($run->status) {
                'success' => 'Correcta', 'running' => 'En curso', 'error', 'failed' => 'Falló', default => 'Sin información'
            },
            'http_status' => $run->http_status,
            'discovered' => $run->discovered,
            'created_candidates' => $run->created_candidates,
            'duration_ms' => $run->duration_ms,
            'error_message' => $run->error === null ? null : 'La consulta falló. Revisá el estado de la conexión y los registros privados del servidor.',
        ]));
        $latestIncidents = Incident::query()->with('category')->whereHas('sources', fn (Builder $query) => $query->where('source_id', $source->id))->latest('published_at')->latest('id')->limit(8)->get();

        return [
            'connection' => $this->connectionRow($source), 'runs' => $runs,
            'latestNews' => $this->newsRows($this->newsQuery()->where('source_id', $source->id)->latest('detected_at')->latest('id')->limit(8)->get())->values()->all(),
            'latestIncidents' => $latestIncidents->map(fn (Incident $incident): array => $this->incidentRow($incident))->all(),
            'scheduler' => $this->scheduler(),
        ];
    }

    private function newsQuery(): Builder
    {
        return RawItem::query()->select(['id', 'source_id', 'title', 'original_url', 'published_at', 'detected_at', 'processed_at'])
            ->with(['source:id,name,slug', 'candidates' => fn ($query) => $query->select(['id', 'raw_item_id', 'status', 'ai_decision'])->orderByDesc('id')]);
    }

    private function filterNewsStatus(Builder $query, string $status): Builder
    {
        $published = IncidentSource::query()->selectRaw('1')->whereColumn('raw_item_id', 'raw_items.id')->whereHas('incident', fn (Builder $incident) => $incident->published());
        if ($status === 'published') {
            return $query->whereExists($published);
        }
        $query->whereNotExists($published);
        if ($status === 'unprocessed') {
            return $query->whereDoesntHave('candidates');
        }

        return $query->where(function (Builder $query) use ($status): void {
            if ($status === 'pending') {
                $query->whereDoesntHave('candidates');
            }
            $query->orWhereHas('candidates', function (Builder $candidate) use ($status): void {
                $candidate->where('content_candidates.id', ContentCandidate::query()->select('id')->from('content_candidates as latest_candidate')
                    ->whereColumn('latest_candidate.raw_item_id', 'raw_items.id')->orderByDesc('latest_candidate.id')->limit(1));
                $candidate->where('status', $status === 'rejected' ? 'like' : 'not like', 'rejected%');
            });
        });
    }

    private function newsRows(Collection $items): Collection
    {
        if ($items->isEmpty()) {
            return collect();
        }
        $references = IncidentSource::query()->with('incident.category')->whereIn('raw_item_id', $items->pluck('id'))->get()->groupBy('raw_item_id');

        return $items->map(function (RawItem $raw) use ($references): array {
            $candidate = $raw->candidates->first();
            $incidents = ($references->get($raw->id) ?? collect())->pluck('incident')->filter()->unique('id');
            $published = $incidents->contains(fn (Incident $incident) => $incident->published_at !== null && $incident->published_at->lte(now()));
            $status = match (true) {
                $published => 'published', $candidate === null => 'unprocessed',
                str_starts_with($candidate->status, 'rejected') => 'rejected', default => 'pending',
            };

            return [
                'id' => $raw->id, 'title' => $this->plainText($raw->title),
                'original_url' => $this->safeUrl($raw->original_url),
                'source' => ['id' => $raw->source->id, 'name' => $raw->source->name, 'slug' => $raw->source->slug],
                'published_at' => $raw->published_at?->toIso8601String(),
                'detected_at' => $raw->detected_at?->toIso8601String(),
                'processed_at' => $raw->processed_at?->toIso8601String(),
                'status' => $status,
                'status_label' => $status === 'published' ? 'Publicada' : ($candidate === null ? 'Sin procesar' : ($candidate->status === 'published' ? 'Sin incidencia publicada' : $this->candidateStatusLabel($candidate->status))),
                'status_tone' => match ($status) {
                    'published' => 'success', 'rejected' => 'muted', default => 'warning'
                },
                'decision' => $candidate?->ai_decision,
                'incident_count' => $incidents->count(),
                'incidents' => $incidents->map(fn (Incident $incident): array => $this->incidentRow($incident))->values()->all(),
            ];
        });
    }

    private function incidentRow(Incident $incident): array
    {
        return [
            'public_id' => $incident->public_id, 'title' => $this->plainText($incident->title),
            'status' => $incident->status->value, 'status_label' => $incident->status->label(),
            'category' => $incident->category->name, 'category_slug' => $incident->category->slug,
            'published_at' => $incident->published_at?->toIso8601String(),
        ];
    }

    private function sourceQuery(): Builder
    {
        return Source::query()->with('latestRun')->withCount('rawItems')->withExists([
            'parserSnapshots as recent_structure_changed' => fn (Builder $query) => $query->where('structure_changed', true)->where('observed_at', '>=', now()->subDay()),
        ]);
    }

    private function connectionRows(): Collection
    {
        return $this->sourceQuery()->orderByDesc('enabled')->orderBy('name')->get()->map(fn (Source $source): array => $this->connectionRow($source));
    }

    private function connectionRow(Source $source): array
    {
        $health = $this->sourceHealth->status($source);
        $code = $health['reason'] ?? null;
        $knownCodes = ['ANTI_BOT_BLOCKED', 'DNS_RESOLUTION_ERROR', 'PARSER_STRUCTURE_CHANGED', 'LAST_SUCCESS_STALE',
            'NEVER_SUCCEEDED', 'HTTP_ERROR', 'TIMEOUT', 'CONNECTION_TIMEOUT', 'COLLECTOR_ERROR', 'COMMAND_ERROR', 'MONITORING_ERROR'];
        $code = in_array($code, $knownCodes, true) ? $code : ($code === null ? null : 'SOURCE_REQUEST_FAILED');
        if ($health['status'] === 'DISABLED_CONFIGURATION') {
            $code = 'DISABLED_CONFIGURATION';
        }
        $run = $source->latestRun;
        $inProgress = $run?->status === 'running' && $run->finished_at === null;

        return [
            'id' => $source->id, 'name' => $source->name, 'slug' => $source->slug,
            'type' => $source->type->value, 'type_label' => $source->type->label(),
            'ingestion_type' => $source->ingestion_type->value, 'enabled' => $source->enabled,
            'domain' => preg_match('/^[a-z0-9.-]+$/i', (string) $source->domain) === 1 ? $source->domain : null,
            'health' => $health['status'], 'health_label' => $this->healthLabel($health['status']),
            'health_tone' => match ($health['status']) {
                'HEALTHY' => 'success', 'FAILING' => 'danger', 'DISABLED', 'DISABLED_CONFIGURATION' => 'muted', default => 'warning'
            },
            'reason_code' => $code, 'reason' => $this->reasonLabel($code, $health['status']),
            'polling_minutes' => $source->polling_minutes,
            'last_run_at' => $source->last_run_at?->toIso8601String(),
            'last_run_finished_at' => $run?->finished_at?->toIso8601String(),
            'last_run_in_progress' => $inProgress,
            'run_status' => $run?->status,
            'run_status_label' => match ($run?->status) {
                'running' => 'En curso', 'success' => 'Completada', 'error', 'failed' => 'Falló', default => 'Sin consultas registradas'
            },
            'last_success_at' => $source->last_success_at?->toIso8601String(),
            'next_run_at' => $source->enabled ? $source->last_run_at?->copy()->addMinutes($source->polling_minutes)->toIso8601String() : null,
            'http_status' => $source->last_http_status ?? $source->latestRun?->http_status,
            'items_seen' => $inProgress ? null : ($run?->discovered ?? $source->items_seen ?? 0),
            'items_new' => $inProgress ? null : ($run?->created_candidates ?? $source->items_new ?? 0),
            'consecutive_errors' => $source->consecutive_errors,
            'duration_ms' => $source->last_duration_ms ?? $source->latestRun?->duration_ms,
            'news_count' => (int) $source->raw_items_count,
        ];
    }

    private function scheduler(): array
    {
        $health = $this->schedulerHealth->status();
        $cycle = $health['last_cycle'];

        return [
            'status' => $health['status'],
            'label' => match ($health['status']) {
                'HEALTHY' => 'Actualización al día', 'RUNNING' => 'Actualizando', 'FAILED' => 'Último ciclo con errores', 'STALE' => 'Actualización demorada', default => 'Sin ciclos registrados'
            },
            'last_cycle' => $cycle === null ? null : [
                'started_at' => $cycle->started_at?->toIso8601String(), 'finished_at' => $cycle->finished_at?->toIso8601String(),
                'status' => $cycle->status, 'sources_processed' => $cycle->sources_processed,
                'candidates_created' => $cycle->candidates_created, 'incidents_created' => $cycle->incidents_created,
            ],
        ];
    }

    private function problematic(Collection $connections): Collection
    {
        return $connections->where('enabled', true)->where('health', '!=', 'HEALTHY');
    }

    private function candidateStatusLabel(string $status): string
    {
        return match ($status) {
            'published' => 'Publicada', 'waiting_location' => 'Pendiente de ubicación',
            'pending_ai' => 'Pendiente de decisión', 'processing' => 'En procesamiento',
            'rejected_rules' => 'Descartada por reglas', 'rejected_category' => 'Categoría no admitida',
            default => 'Pendiente de revisión',
        };
    }

    private function healthLabel(string $status): string
    {
        return match ($status) {
            'HEALTHY' => 'Conectada', 'FAILING' => 'Fallos reiterados', 'DEGRADED' => 'Requiere atención',
            'STALE' => 'Datos demorados', 'DISABLED_CONFIGURATION' => 'Falta configuración', default => 'Desactivada',
        };
    }

    private function reasonLabel(?string $code, string $health): string
    {
        return match ($code) {
            'ANTI_BOT_BLOCKED' => 'La fuente bloquea las consultas automáticas.',
            'DNS_RESOLUTION_ERROR' => 'No se pudo resolver el dominio de la fuente.',
            'PARSER_STRUCTURE_CHANGED' => 'El formato de la fuente cambió y requiere revisión.',
            'LAST_SUCCESS_STALE' => 'La última consulta correcta superó el plazo esperado.',
            'NEVER_SUCCEEDED' => 'Todavía no hay una consulta correcta registrada.',
            'HTTP_ERROR' => 'La fuente respondió con un error HTTP.',
            'TIMEOUT', 'CONNECTION_TIMEOUT' => 'La consulta superó el tiempo de espera.',
            null => match ($health) {
                'HEALTHY' => 'Las últimas consultas se completaron correctamente.', 'DISABLED', 'DISABLED_CONFIGURATION' => 'Esta fuente no participa en el ciclo automático.', default => 'La conexión requiere revisión.'
            },
            default => in_array($health, ['DISABLED', 'DISABLED_CONFIGURATION'], true) ? 'Esta fuente no participa en el ciclo automático.' : 'La consulta no pudo completarse. Revisá los registros privados del servidor.',
        };
    }

    private function plainText(?string $value): string
    {
        $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~<(script|style|noscript)\b[^>]*>.*?</\1\s*>~is', '', $text) ?? '';
        $text = preg_replace('~</?(?:p|div|br|li|h[1-6]|article|section)\b[^>]*>~i', "\n", $text) ?? '';

        return trim(preg_replace('/[ \t]+/u', ' ', strip_tags($text)) ?? '');
    }

    private function safeExplanation(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = $this->plainText($value);
        $text = preg_replace('~https?://[^\s<>]+~i', '[enlace omitido]', $text) ?? '';
        $text = preg_replace('/\b(?:token|api[_ -]?key|secret|password|authorization)\s*[:=]\s*\S+/i', '[credencial omitida]', $text) ?? '';

        return Str::limit($text, 2000);
    }

    private function safeUrl(string $value): ?string
    {
        $parts = parse_url($value);
        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $url = strtolower($parts['scheme']).'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/');
        parse_str($parts['query'] ?? '', $query);
        // Preserve public article identifiers and CMS routing; never forward credentials or redirects.
        $publicParameters = [
            'id', 'nid', 'p', 'post', 'article', 'noticia', 'nota', 'cat', 'page',
            'id_noticia', 'noticia_id', 'id_nota', 'nota_id', 'not_id', 'news_id',
            'id_news', 'article_id', 'story', 'story_id', 'cod', 'codigo', 'slug',
            'tipo_nota', 'option', 'view', 'itemid',
        ];
        $query = array_filter($query, fn ($value, $key) => in_array(strtolower($key), $publicParameters, true) && is_scalar($value), ARRAY_FILTER_USE_BOTH);

        return $url.($query === [] ? '' : '?'.http_build_query($query));
    }

    private function likeTerm(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }

    private function filterText(Request $request, string $key, int $length = 120): string
    {
        $value = $request->query($key, '');

        return is_scalar($value) ? Str::limit(trim((string) $value), $length, '') : '';
    }
}
