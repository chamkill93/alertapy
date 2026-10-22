<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DataRetentionService
{
    public const DAYS = 7;

    public function cutoff(): CarbonImmutable
    {
        return CarbonImmutable::now()->subDays(self::DAYS);
    }

    /**
     * Dates describe the content itself. A collector touching updated_at or
     * last_seen_at cannot extend the retention of an old article or event.
     *
     * @return array{cutoff: string, dry_run: bool, records: array<string, int>, photos: int}
     */
    public function prune(bool $dryRun = false): array
    {
        $cutoff = $this->cutoff();
        $queries = $this->queries($cutoff);
        $records = [];
        $photos = $this->expiredPhotos($cutoff);

        if ($dryRun) {
            foreach ($queries as $table => $query) {
                $records[$table] = (clone $query)->count();
            }
        } else {
            // Keep content and relational evidence atomic. File removal only
            // follows a successful commit, and orphan cleanup can retry it.
            DB::transaction(function () use ($queries, &$records): void {
                foreach ($queries as $table => $query) {
                    $records[$table] = 0;
                    do {
                        $ids = (clone $query)->orderBy('id')->limit(500)->pluck('id');
                        if ($ids->isNotEmpty()) {
                            $records[$table] += DB::table($table)->whereIn('id', $ids)->delete();
                        }
                    } while ($ids->isNotEmpty());
                }
            });

            foreach ($photos as $path) {
                if (! Storage::disk('local')->delete($path)) {
                    throw new RuntimeException('No se pudo eliminar una foto vencida de community-reports. La próxima purga reintentará los archivos huérfanos.');
                }
            }
        }

        return [
            'cutoff' => $cutoff->toIso8601String(),
            'dry_run' => $dryRun,
            'records' => $records,
            'photos' => count($photos),
        ];
    }

    /** @return array<string, Builder> Deletion order preserves FK dependencies. */
    private function queries(CarbonImmutable $cutoff): array
    {
        $oldIncidents = $this->oldIncidents($cutoff)->select('id');
        $oldRaw = $this->oldRawItems($cutoff)->select('id');

        return [
            'content_candidates' => DB::table('content_candidates')->where(fn (Builder $query) => $query
                ->where('created_at', '<', $cutoff)->orWhereIn('raw_item_id', $oldRaw)),
            'incident_updates' => DB::table('incident_updates')->where(fn (Builder $query) => $query
                ->whereRaw('COALESCE(occurred_at, created_at) < ?', [$cutoff])->orWhereIn('incident_id', $oldIncidents)),
            'community_incident_votes' => DB::table('community_incident_votes')->where(fn (Builder $query) => $query
                ->where('created_at', '<', $cutoff)->orWhereIn('incident_id', $oldIncidents)),
            'location_audits' => DB::table('location_audits')->where(fn (Builder $query) => $query
                ->where('audited_at', '<', $cutoff)->orWhereIn('incident_id', $oldIncidents)),
            // An original URL remains with a retained incident even when its
            // raw article expires, so every current event keeps attribution.
            'incident_sources' => DB::table('incident_sources')->whereIn('incident_id', $oldIncidents),
            'incidents' => $this->oldIncidents($cutoff),
            'raw_items' => $this->oldRawItems($cutoff),
            'source_runs' => DB::table('source_runs')->where('started_at', '<', $cutoff),
            'scheduler_cycles' => DB::table('scheduler_cycles')->where('started_at', '<', $cutoff),
            'source_parser_snapshots' => DB::table('source_parser_snapshots')->where('observed_at', '<', $cutoff),
            'station_measurements' => DB::table('station_measurements')->where('measured_at', '<', $cutoff),
            'weather_alerts' => DB::table('weather_alerts')->whereRaw('COALESCE(sent_at, starts_at, created_at) < ?', [$cutoff]),
        ];
    }

    private function oldIncidents(CarbonImmutable $cutoff): Builder
    {
        return DB::table('incidents')->where(fn (Builder $query) => $query
            ->whereRaw('COALESCE(occurred_at, starts_at, published_at, detected_at, created_at) < ?', [$cutoff])
            ->orWhereRaw('COALESCE(published_at, detected_at, created_at) < ?', [$cutoff]));
    }

    private function oldRawItems(CarbonImmutable $cutoff): Builder
    {
        return DB::table('raw_items')->whereRaw('COALESCE(published_at, detected_at, created_at) < ?', [$cutoff]);
    }

    /** @return list<string> */
    private function expiredPhotos(CarbonImmutable $cutoff): array
    {
        $disk = Storage::disk('local');
        $paths = $this->oldIncidents($cutoff)->whereNotNull('community_photo_path')->pluck('community_photo_path')->all();

        // A failed prior deletion may have left a file after its incident was
        // removed. Retry only this dedicated directory and only expired files.
        foreach ($disk->files('community-reports') as $path) {
            if ($disk->lastModified($path) < $cutoff->getTimestamp()) {
                $paths[] = $path;
            }
        }

        return array_values(array_filter(array_unique($paths), function ($path) use ($disk, $cutoff): bool {
            if (! is_string($path) || preg_match('#\Acommunity-reports/[A-Za-z0-9][A-Za-z0-9._-]*\z#', $path) !== 1 || ! $disk->exists($path)) {
                return false;
            }

            // Never remove a file shared by an event still inside the window.
            return ! DB::table('incidents')->where('community_photo_path', $path)
                ->whereRaw('COALESCE(occurred_at, starts_at, published_at, detected_at, created_at) >= ?', [$cutoff])
                ->whereRaw('COALESCE(published_at, detected_at, created_at) >= ?', [$cutoff])->exists();
        }));
    }
}
