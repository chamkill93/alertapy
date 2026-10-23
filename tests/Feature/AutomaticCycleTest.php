<?php

namespace Tests\Feature;

use App\Collectors\DmhAutomaticStationCollector;
use App\Collectors\DmhCapAlertCollector;
use App\Console\Commands\RunCycleCommand;
use App\Models\ContentCandidate;
use App\Models\SchedulerCycle;
use App\Models\Source;
use App\Services\SchedulerHealthService;
use App\Services\SourceCommandRegistry;
use App\Services\SourceHealthService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AutomaticCycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('alertapy.cycle', ['max_sources' => 20, 'max_seconds' => 180, 'max_candidates' => 100]);
    }

    public function test_cycle_covers_both_dmh_feeds_and_dynamic_press_with_due_frequency(): void
    {
        $this->source('dmh-automatic-stations');
        $this->source('dmh-cap');
        $this->source('latribuna', ['configuration_json' => ['collector' => 'press_homepage']]);
        $this->source('regional-new', ['configuration_json' => ['collector' => 'regional_press']]);
        $this->source('municipal-new', ['configuration_json' => ['collector' => 'municipal']]);
        $this->source('utility-new', ['configuration_json' => ['collector' => 'utility_news']]);
        $this->source('abc', ['last_run_at' => now()]);
        $this->source('essap', ['enabled' => false]);
        $this->source('unsupported');

        Artisan::shouldReceive('call')->once()->with('alertapy:sync-monitoring', ['source' => 'dmh-automatic-stations'])->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('alertapy:sync-monitoring', ['source' => 'dmh-cap'])->andReturn(0);
        foreach (['latribuna', 'regional-new', 'municipal-new', 'utility-new'] as $slug) {
            Artisan::shouldReceive('call')->once()->with('alertapy:scrape', ['source' => $slug])->andReturn(0);
        }
        Artisan::shouldReceive('output')->times(6)->andReturn('ok');

        $this->assertSame(0, $this->runCycle());
        $this->assertDatabaseHas('scheduler_cycles', ['status' => 'success', 'sources_processed' => 6]);
    }

    public function test_source_failure_is_recorded_and_does_not_stop_remaining_sources(): void
    {
        $failed = $this->source('abc');
        $last = $this->source('essap');
        Artisan::shouldReceive('call')->once()->with('alertapy:scrape', ['source' => 'abc'])->andThrow(new RuntimeException('Provider unavailable'));
        Artisan::shouldReceive('call')->once()->with('alertapy:scrape', ['source' => 'essap'])->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('ok');

        $this->assertSame(1, $this->runCycle());
        $this->assertDatabaseHas('scheduler_cycles', ['status' => 'failed', 'sources_processed' => 2, 'error_summary' => 'Fuentes con error: abc']);
        $this->assertDatabaseHas('source_runs', ['source_id' => $failed->id, 'status' => 'error', 'error' => 'Provider unavailable']);
        $this->assertSame(1, $failed->fresh()->consecutive_errors);
        $this->assertSame($last->id, Cache::get('alertapy:cycle:source-cursor'));
        $this->assertTrue(Cache::lock('alertapy:general-cycle', 30)->get());
    }

    public function test_source_limit_and_checkpoint_wrap_resume_fairly(): void
    {
        $first = $this->source('abc');
        $middle = $this->source('essap');
        $this->source('meteorologia');
        Cache::forever('alertapy:cycle:source-cursor', $middle->id);
        config()->set('alertapy.cycle.max_sources', 2);
        Artisan::shouldReceive('call')->once()->ordered()->with('alertapy:scrape', ['source' => 'meteorologia'])->andReturn(0);
        Artisan::shouldReceive('call')->once()->ordered()->with('alertapy:scrape', ['source' => 'abc'])->andReturn(0);
        Artisan::shouldReceive('output')->twice()->andReturn('ok');

        $this->assertSame(0, $this->runCycle());
        $this->assertSame($first->id, Cache::get('alertapy:cycle:source-cursor'));
        $this->assertDatabaseHas('scheduler_cycles', ['sources_processed' => 2]);
    }

    public function test_candidate_budget_stops_before_the_next_source_and_preserves_checkpoint(): void
    {
        $source = $this->source('abc');
        $this->source('essap');
        config()->set('alertapy.cycle.max_candidates', 1);
        Artisan::shouldReceive('call')->once()->with('alertapy:scrape', ['source' => 'abc'])->andReturnUsing(function () use ($source) {
            ContentCandidate::query()->create(['source_id' => $source->id, 'source_type' => 'official', 'normalized_content' => 'Evidence']);

            return 0;
        });
        Artisan::shouldReceive('output')->once()->andReturn('ok');

        $this->assertSame(0, $this->runCycle());
        $this->assertSame($source->id, Cache::get('alertapy:cycle:source-cursor'));
        $this->assertDatabaseHas('scheduler_cycles', ['sources_processed' => 1, 'candidates_created' => 1]);
    }

    public function test_due_service_sources_run_first_without_skipping_the_regular_checkpoint(): void
    {
        config()->set('alertapy.cycle.priority_sources', ['essap', 'siaguapy']);
        config()->set('alertapy.cycle.max_sources', 2);
        $first = $this->source('abc');
        $this->source('essap');
        $next = $this->source('meteorologia');
        $this->source('siaguapy', ['last_run_at' => now()]);
        Cache::forever('alertapy:cycle:source-cursor', $first->id);
        Artisan::shouldReceive('call')->once()->ordered()->with('alertapy:scrape', ['source' => 'essap'])->andReturn(0);
        Artisan::shouldReceive('call')->once()->ordered()->with('alertapy:scrape', ['source' => 'meteorologia'])->andReturn(0);
        Artisan::shouldReceive('output')->twice()->andReturn('ok');

        $this->assertSame(0, $this->runCycle());
        $this->assertSame($next->id, Cache::get('alertapy:cycle:source-cursor'));
        $this->assertDatabaseHas('scheduler_cycles', ['sources_processed' => 2]);
    }

    public function test_a_priority_only_batch_does_not_rewind_the_general_queue(): void
    {
        config()->set('alertapy.cycle.priority_sources', ['essap']);
        config()->set('alertapy.cycle.max_sources', 1);
        $first = $this->source('abc');
        $priority = $this->source('essap');
        $next = $this->source('meteorologia');
        Cache::forever('alertapy:cycle:source-cursor', $first->id);
        Artisan::shouldReceive('call')->once()->with('alertapy:scrape', ['source' => 'essap'])->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('ok');

        $this->assertSame(0, $this->runCycle());
        $this->assertSame($first->id, Cache::get('alertapy:cycle:source-cursor'));

        $priority->update(['last_run_at' => now()]);
        Artisan::shouldReceive('call')->once()->with('alertapy:scrape', ['source' => 'meteorologia'])->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('ok');
        $this->assertSame(0, $this->runCycle());
        $this->assertSame($next->id, Cache::get('alertapy:cycle:source-cursor'));
    }

    public function test_priority_sources_run_in_due_order_and_keep_failures_visible(): void
    {
        config()->set('alertapy.cycle.priority_sources', ['essap', 'siaguapy', 'latribuna']);
        $this->source('abc');
        $this->source('essap', ['last_run_at' => now()->subMinutes(20)]);
        $this->source('siaguapy', ['last_run_at' => now()->subHour()]);
        $this->source('latribuna', [
            'last_run_at' => now()->subHours(2),
            'configuration_json' => ['collector' => 'press_homepage'],
        ]);
        Artisan::shouldReceive('call')->once()->ordered()->with('alertapy:scrape', ['source' => 'latribuna'])->andReturn(0);
        Artisan::shouldReceive('call')->once()->ordered()->with('alertapy:scrape', ['source' => 'siaguapy'])->andReturn(1);
        Artisan::shouldReceive('call')->once()->ordered()->with('alertapy:scrape', ['source' => 'essap'])->andReturn(0);
        Artisan::shouldReceive('call')->once()->ordered()->with('alertapy:scrape', ['source' => 'abc'])->andReturn(0);
        Artisan::shouldReceive('output')->times(4)->andReturn('ok');

        $this->assertSame(1, $this->runCycle());
        $this->assertDatabaseHas('scheduler_cycles', ['sources_processed' => 4, 'error_summary' => 'Fuentes con error: siaguapy']);
    }

    public function test_a_running_cycle_holds_the_lock_and_does_not_create_a_duplicate_cycle(): void
    {
        $lock = Cache::lock('alertapy:general-cycle', SourceCommandRegistry::lockSeconds());
        $this->assertTrue($lock->get());
        $this->assertSame(0, $this->runCycle());
        $this->assertDatabaseCount('scheduler_cycles', 0);
        $lock->release();
        $this->assertSame(0, $this->runCycle());
        $this->assertDatabaseCount('scheduler_cycles', 1);
    }

    public function test_time_budget_finishes_current_source_and_defers_the_next(): void
    {
        $source = $this->source('abc');
        $this->source('essap');
        config()->set('alertapy.cycle.max_seconds', 1);
        Artisan::shouldReceive('call')->once()->with('alertapy:scrape', ['source' => 'abc'])->andReturnUsing(function () {
            usleep(1100000);

            return 0;
        });
        Artisan::shouldReceive('output')->once()->andReturn('ok');

        $this->assertSame(0, $this->runCycle());
        $this->assertSame($source->id, Cache::get('alertapy:cycle:source-cursor'));
        $this->assertDatabaseHas('scheduler_cycles', ['sources_processed' => 1, 'status' => 'success']);
    }

    public function test_lock_is_released_even_if_cycle_initialization_fails(): void
    {
        SchedulerCycle::creating(function () {
            throw new RuntimeException('Cannot initialize cycle');
        });

        try {
            $this->runCycle();
            $this->fail('The initialization error must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cannot initialize cycle', $exception->getMessage());
        }

        $lock = Cache::lock('alertapy:general-cycle', 30);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_monitoring_does_not_sync_while_another_process_holds_its_lock(): void
    {
        $this->source('dmh-automatic-stations');
        $this->mock(DmhAutomaticStationCollector::class)->shouldNotReceive('sync');
        $lock = Cache::lock('alertapy:monitoring:dmh-automatic-stations', 30);
        $this->assertTrue($lock->get());

        $this->artisan('alertapy:sync-monitoring dmh-automatic-stations --force')->assertSuccessful();

        $this->assertDatabaseCount('source_runs', 0);
        $lock->release();
    }

    public function test_scheduler_schedules_master_cycle_and_periodic_map_data_syncs(): void
    {
        $events = collect(app(Schedule::class)->events());
        $this->assertCount(1, $events->filter(fn ($event) => str_contains($event->command ?? '', 'alertapy:cycle')));
        $this->assertCount(1, $events->filter(fn ($event) => str_contains($event->command ?? '', 'alertapy:sync-ine-territories')));
        $this->assertCount(1, $events->filter(fn ($event) => str_contains($event->command ?? '', 'alertapy:sync-critical-infrastructure')));
        $this->assertCount(0, $events->filter(fn ($event) => preg_match('/alertapy:(scrape|discover|sync-monitoring)\b/', $event->command ?? '') === 1));
    }

    public function test_monitoring_records_runs_obeys_frequency_and_releases_its_lock_after_failure(): void
    {
        $source = $this->source('dmh-automatic-stations');
        $this->mock(DmhAutomaticStationCollector::class)->shouldReceive('sync')->once()->andReturn(['stations' => 2, 'measurements' => 4]);
        $this->artisan('alertapy:sync-monitoring dmh-automatic-stations')->assertSuccessful();
        $this->artisan('alertapy:sync-monitoring dmh-automatic-stations')->assertSuccessful();
        $this->assertDatabaseCount('source_runs', 1);
        $this->assertDatabaseHas('source_runs', ['source_id' => $source->id, 'status' => 'success', 'discovered' => 2]);

        $this->mock(DmhAutomaticStationCollector::class)->shouldReceive('sync')->once()->andThrow(new RuntimeException('HTTP 503'));
        $this->artisan('alertapy:sync-monitoring dmh-automatic-stations --force')->assertFailed();
        $this->assertDatabaseHas('source_runs', ['source_id' => $source->id, 'status' => 'error']);
        $this->assertSame(1, $source->fresh()->consecutive_errors);
        $this->assertTrue(Cache::lock('alertapy:monitoring:dmh-automatic-stations', 30)->get());
    }

    public function test_cap_alerts_use_the_correct_collector_and_keep_failure_evidence(): void
    {
        $source = $this->source('dmh-cap');
        $this->mock(DmhCapAlertCollector::class)->shouldReceive('sync')->once()->withNoArgs()->andReturn(['received' => 7, 'stored' => 2]);
        $this->artisan('alertapy:sync-monitoring dmh-cap')->assertSuccessful();
        $this->assertDatabaseHas('source_runs', ['source_id' => $source->id, 'status' => 'success', 'discovered' => 7]);
        $this->assertSame(2, $source->fresh()->items_new);
    }

    public function test_health_flags_abandoned_running_cycle_and_old_source_success(): void
    {
        config()->set('alertapy.scheduler_stale_minutes', 15);
        SchedulerCycle::query()->create(['started_at' => now()->subHour(), 'status' => 'running']);
        $this->assertSame('STALE', app(SchedulerHealthService::class)->status()['status']);
        $source = $this->source('abc', ['last_run_at' => now()->subMonth(), 'last_success_at' => now()->subMonth()]);
        $health = app(SourceHealthService::class)->status($source);
        $this->assertSame('STALE', $health['status']);
        $this->assertSame('LAST_SUCCESS_STALE', $health['reason']);
        $source->update(['last_run_at' => now(), 'last_success_at' => now()]);
        $this->assertSame('HEALTHY', app(SourceHealthService::class)->status($source->fresh())['status']);
    }

    private function runCycle(): int
    {
        $command = app(RunCycleCommand::class);
        $command->setLaravel($this->app);

        return $command->run(new ArrayInput([]), new BufferedOutput);
    }

    private function source(string $slug, array $attributes = []): Source
    {
        return Source::query()->create([
            'name' => $slug, 'slug' => $slug, 'type' => 'official',
            'base_url' => 'https://example.test/', 'domain' => 'example.test',
            'enabled' => true, 'polling_minutes' => 5, ...$attributes,
        ]);
    }
}
