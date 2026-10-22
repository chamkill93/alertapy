<?php

namespace Tests\Feature;

use App\Enums\FreshnessStatus;
use App\Enums\IncidentStatus;
use App\Enums\LifecycleStatus;
use App\Enums\LocationAccuracy;
use App\Enums\Severity;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Incident;
use App\Services\IncidentExpirationService;
use App\Services\IncidentFreshnessService;
use App\Services\IncidentLifecycleService;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentExpirationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22 14:00:00 America/Asuncion'));
        $this->seed(CategorySeeder::class);
    }

    public function test_expiration_persists_expired_lifecycle_once_and_preserves_resolution_semantics(): void
    {
        $past = $this->incident(IncidentStatus::Active, ['expires_at' => now()->subMinute()]);
        $atBoundary = $this->incident(IncidentStatus::Reported, ['expires_at' => now()]);
        $future = $this->incident(IncidentStatus::Active, ['expires_at' => now()->addMinute()]);
        $noExpiry = $this->incident(IncidentStatus::Confirmed, ['expires_at' => null]);
        $resolved = $this->incident(IncidentStatus::Finalized, ['expires_at' => now()->subHour(), 'resolved_at' => now()->subHour()]);
        $cancelled = $this->incident(IncidentStatus::Cancelled, ['expires_at' => now()->subHour()]);
        $service = app(IncidentExpirationService::class);

        $this->assertSame(['checked' => 2, 'expired' => 2], $service->expire());
        $lifecycle = app(IncidentLifecycleService::class);
        foreach ([$past, $atBoundary] as $incident) {
            $incident->refresh();
            $this->assertSame(IncidentStatus::Finalized, $incident->status);
            $this->assertSame(LifecycleStatus::Expired, $incident->lifecycle_status);
            $this->assertSame(LifecycleStatus::Expired, $lifecycle->derive($incident));
            $this->assertSame(FreshnessStatus::Archived, app(IncidentFreshnessService::class)->forIncident($incident));
            $this->assertTrue($incident->expired_at->equalTo($incident->expires_at));
            $this->assertNull($incident->resolved_at);
            $this->assertDatabaseHas('incident_updates', ['incident_id' => $incident->id, 'event_type' => 'expired']);
        }
        $this->assertSame(IncidentStatus::Active, $future->fresh()->status);
        $this->assertSame(IncidentStatus::Confirmed, $noExpiry->fresh()->status);
        $this->assertSame(LifecycleStatus::Resolved, $lifecycle->derive($resolved->fresh()));
        $this->assertSame(LifecycleStatus::Discarded, $lifecycle->derive($cancelled->fresh()));
        $this->assertSame(['checked' => 0, 'expired' => 0], $service->expire());
        $this->assertDatabaseCount('incident_updates', 2);
    }

    public function test_dry_run_and_batch_limit_do_not_lose_remaining_expirations(): void
    {
        $first = $this->incident(IncidentStatus::Scheduled, ['expires_at' => now()->subMinute()]);
        $second = $this->incident(IncidentStatus::UnderVerification, ['expires_at' => now()->subMinute()]);
        $service = app(IncidentExpirationService::class);

        $this->assertSame(['checked' => 2, 'expired' => 2], $service->expire(true));
        $this->assertSame(IncidentStatus::Scheduled, $first->fresh()->status);
        $this->assertSame(IncidentStatus::UnderVerification, $second->fresh()->status);
        $this->assertDatabaseCount('incident_updates', 0);

        $this->assertSame(['checked' => 1, 'expired' => 1], $service->expire(false, 1));
        $this->assertSame(IncidentStatus::Finalized, $first->fresh()->status);
        $this->assertSame(IncidentStatus::UnderVerification, $second->fresh()->status);
        $this->assertSame(['checked' => 1, 'expired' => 1], $service->expire(false, 1));
        $this->assertSame(IncidentStatus::Finalized, $second->fresh()->status);
        $this->assertDatabaseCount('incident_updates', 2);
    }

    public function test_cycle_applies_expiration_even_without_due_sources(): void
    {
        $incident = $this->incident(IncidentStatus::Active, ['expires_at' => now()->subMinute()]);

        $this->artisan('alertapy:cycle')->assertSuccessful();

        $this->assertSame(LifecycleStatus::Expired, app(IncidentLifecycleService::class)->derive($incident->fresh()));
        $this->assertDatabaseHas('scheduler_cycles', ['status' => 'success', 'sources_processed' => 0, 'incidents_closed' => 1]);
    }

    private function incident(IncidentStatus $status, array $overrides = []): Incident
    {
        return Incident::query()->create([
            'category_id' => Category::query()->where('slug', 'fire')->value('id'),
            'title' => 'Incendio en depósito', 'summary' => 'Evidencia territorial', 'source_type' => SourceType::Official,
            'verification_level' => 'official', 'status' => $status, 'severity' => Severity::Medium,
            'latitude' => -25.3, 'longitude' => -57.6, 'location_accuracy' => LocationAccuracy::City,
            'location_text' => 'Capiatá', 'city' => 'Capiatá', 'department' => 'Central',
            'starts_at' => now()->subHours(18), 'published_at' => now()->subHours(18), ...$overrides,
        ]);
    }
}
