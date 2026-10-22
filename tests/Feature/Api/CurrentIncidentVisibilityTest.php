<?php

namespace Tests\Feature\Api;

use App\Enums\IncidentStatus;
use App\Enums\LifecycleStatus;
use App\Enums\LocationAccuracy;
use App\Enums\Severity;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Incident;
use App\Models\WeatherAlert;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentIncidentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22 14:00:00 America/Asuncion'));
    }

    public function test_now_requires_an_event_to_have_started_and_not_ended_or_closed(): void
    {
        $ongoing = $this->incident('electricity');
        $startedSchedule = $this->incident('water', ['status' => IncidentStatus::Scheduled]);
        foreach ([
            ['starts_at' => now()->addMinute(), 'status' => IncidentStatus::Scheduled],
            ['occurred_at' => now()->addMinute()],
            ['ends_at' => now()],
            ['expires_at' => now()],
            ['resolved_at' => now()->subMinute()],
            ['expired_at' => now()->subMinute()],
            ['finalized_at' => now()->subMinute()],
            ['lifecycle_status' => LifecycleStatus::Resolved],
            ['lifecycle_status' => LifecycleStatus::Expired],
            ['lifecycle_status' => LifecycleStatus::Discarded],
            ['status' => IncidentStatus::Cancelled],
            ['status' => IncidentStatus::Finalized],
        ] as $overrides) {
            $this->incident('fire', $overrides);
        }

        $response = $this->getJson('/api/incidents?period=now')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$ongoing->public_id, $startedSchedule->public_id], array_column($response->json('data'), 'id'));
        $this->getJson('/api/nearby/summary?lat=-25.30&lng=-57.60&radius_km=5')->assertOk()->assertJsonPath('data.incident_count', 2);
    }

    public function test_unknown_validity_expires_by_category_and_ignores_scrape_and_edit_times(): void
    {
        foreach (['roads' => 8, 'weather_flood' => 12, 'traffic_accident' => 18, 'fire' => 18, 'emergency' => 18, 'water' => 24] as $slug => $hours) {
            $this->incident($slug, [
                'occurred_at' => now()->subHours($hours), 'starts_at' => now()->subHours($hours),
                'expires_at' => null, 'last_seen_at' => now(), 'updated_at' => now(),
            ]);
            $this->incident($slug, [
                'occurred_at' => null, 'starts_at' => now()->subHours($hours)->addMinute(), 'expires_at' => null,
            ]);
        }
        // A real explicit interval may last longer than a fallback policy.
        $this->incident('roads', ['starts_at' => now()->subHours(30), 'ends_at' => now()->addHour(), 'expires_at' => null]);

        $this->getJson('/api/incidents?period=now')->assertOk()->assertJsonCount(7, 'data');
        $this->getJson('/api/incidents?period=7d')->assertOk()->assertJsonCount(13, 'data');
    }

    public function test_recent_publication_does_not_revive_an_event_older_than_seven_days(): void
    {
        $old = $this->incident('electricity', ['occurred_at' => now()->subDays(7)->subSecond(), 'published_at' => now()->subMinute()]);
        $boundary = $this->incident('water', ['occurred_at' => now()->subDays(7), 'published_at' => now()->subMinute()]);

        foreach (['now', '1h', '6h', '24h', '7d'] as $period) {
            $this->getJson('/api/incidents?period='.$period)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $boundary->public_id);
        }
        $this->getJson('/api/incidents/'.$old->public_id)->assertNotFound();
        $this->getJson('/api/incidents/updates')->assertOk()->assertJsonMissing(['incident_id' => $old->public_id]);
    }

    public function test_historical_period_is_no_longer_available(): void
    {
        $this->getJson('/api/incidents?period=historical')->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->get('/')->assertOk()->assertDontSee('data-period="historical"', false)
            ->assertSee('class="active" data-period="now" aria-pressed="true"', false);
    }

    public function test_a_future_schedule_does_not_keep_an_article_older_than_seven_days_public(): void
    {
        $old = $this->incident('electricity', [
            'status' => IncidentStatus::Scheduled, 'starts_at' => now()->addDay(), 'occurred_at' => now()->addDay(),
            'expires_at' => now()->addDays(2), 'published_at' => now()->subDays(7)->subSecond(),
        ]);
        $boundary = $this->incident('water', [
            'status' => IncidentStatus::Scheduled, 'starts_at' => now()->addDay(), 'occurred_at' => now()->addDay(),
            'expires_at' => now()->addDays(2), 'published_at' => now()->subDays(7),
        ]);

        $this->getJson('/api/incidents?period=now')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/incidents?period=7d')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $boundary->public_id);
        $this->getJson('/api/incidents/'.$old->public_id)->assertNotFound();
        $this->getJson('/api/incidents/'.$boundary->public_id)->assertOk();
        $this->getJson('/api/incidents/updates')->assertOk()->assertJsonMissing(['incident_id' => $old->public_id]);
    }

    public function test_now_weather_alerts_without_expiry_do_not_remain_current_forever(): void
    {
        foreach ([
            ['external_id' => 'current-known-end', 'sent_at' => now()->subDay(), 'expires_at' => now()->addHour()],
            ['external_id' => 'current-no-end', 'sent_at' => now()->subHour()],
            ['external_id' => 'expired-no-end', 'sent_at' => now()->subHours(12)],
            ['external_id' => 'future-start', 'sent_at' => now(), 'starts_at' => now()->addHour(), 'expires_at' => now()->addHours(2)],
            ['external_id' => 'future-publication', 'sent_at' => now()->addMinute(), 'expires_at' => now()->addHour()],
            ['external_id' => 'outside-retention', 'sent_at' => now()->subDays(8), 'expires_at' => now()->addHour()],
        ] as $attributes) {
            WeatherAlert::query()->create([
                'event' => 'Tormenta', 'headline' => $attributes['external_id'], 'severity' => 'Severe',
                'geometry_geojson' => ['type' => 'Polygon', 'coordinates' => [[[-57.7, -25.4], [-57.5, -25.4], [-57.5, -25.2], [-57.7, -25.2], [-57.7, -25.4]]]],
                'source_url' => 'https://meteorologia.gov.py', 'synced_at' => now(), ...$attributes,
            ]);
        }

        $response = $this->getJson('/api/nearby/summary?lat=-25.30&lng=-57.60&radius_km=5')->assertOk()->assertJsonCount(2, 'data.weather_alerts');
        $this->assertEqualsCanonicalizing(['current-known-end', 'current-no-end'], array_column($response->json('data.weather_alerts'), 'headline'));
    }

    private function incident(string $slug, array $overrides = []): Incident
    {
        $category = Category::query()->firstOrCreate(['slug' => $slug], [
            'name' => $slug, 'icon' => 'test', 'color' => '#123456', 'enabled' => true, 'sort_order' => 1,
        ]);

        return Incident::query()->create([
            'category_id' => $category->id, 'title' => 'Incidencia de prueba', 'summary' => 'Solo dentro de PHPUnit',
            'source_type' => SourceType::Official, 'verification_level' => 'official', 'status' => IncidentStatus::Active,
            'severity' => Severity::Medium, 'latitude' => -25.30, 'longitude' => -57.60,
            'location_accuracy' => LocationAccuracy::City, 'location_text' => 'Asunción',
            'starts_at' => now()->subHour(), 'published_at' => now()->subHour(), 'expires_at' => now()->addHour(),
            ...$overrides,
        ]);
    }
}
