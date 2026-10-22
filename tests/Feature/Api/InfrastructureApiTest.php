<?php

namespace Tests\Feature\Api;

use App\Models\CriticalFacility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InfrastructureApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabling_more_layers_preserves_all_facilities_under_the_global_limit(): void
    {
        $this->createFacilities('education', 2013);
        $this->createFacilities('health', 200);
        $this->createFacilities('police', 258);

        $education = $this->getJson('/api/infrastructure?bbox=-25.5,-57.8,-25.1,-57.3&kinds=education&zoom=11')
            ->assertOk()->assertJsonCount(2013, 'data');

        $all = $this->getJson('/api/infrastructure?bbox=-25.5,-57.8,-25.1,-57.3&kinds=health,education,police&zoom=11')
            ->assertOk()
            ->assertJsonCount(2471, 'data')
            ->assertJsonPath('meta.total', 2471)
            ->assertJsonPath('meta.truncated', false)
            ->assertJsonPath('meta.aggregated', false);

        $this->assertSame(
            collect($education->json('data'))->pluck('id')->all(),
            collect($all->json('data'))->where('kind', 'education')->pluck('id')->all(),
        );
    }

    public function test_over_limit_views_keep_smaller_layers_and_use_the_remaining_capacity(): void
    {
        $this->createFacilities('education', 3200);
        $this->createFacilities('health', 20);
        $this->createFacilities('police', 30);

        $response = $this->getJson('/api/infrastructure?bbox=-25.5,-57.8,-25.1,-57.3&kinds=health,education,police&zoom=11')
            ->assertOk()
            ->assertJsonCount(3000, 'data')
            ->assertJsonPath('meta.total', 3250)
            ->assertJsonPath('meta.truncated', true);

        $facilities = collect($response->json('data'));
        $this->assertCount(20, $facilities->where('kind', 'health'));
        $this->assertCount(30, $facilities->where('kind', 'police'));
        $this->assertCount(2950, $facilities->where('kind', 'education'));
        $this->assertCount(3000, $facilities->unique('id'));
    }

    public function test_valid_views_outside_paraguay_return_an_empty_layer(): void
    {
        $this->createFacilities('health', 1);

        foreach ([6, 11] as $zoom) {
            $this->getJson('/api/infrastructure?bbox=-35,-70,-30,-65&kinds=health&zoom='.$zoom)
                ->assertOk()
                ->assertJsonCount(0, 'data')
                ->assertJsonPath('meta.total', 0)
                ->assertJsonPath('meta.truncated', false)
                ->assertJsonPath('meta.aggregated', $zoom < 8);
        }
    }

    public function test_outside_views_do_not_bypass_parameter_validation(): void
    {
        $this->getJson('/api/infrastructure?bbox=-35,-70,-30,-65&kinds=unknown&zoom=6')
            ->assertUnprocessable();
        $this->getJson('/api/infrastructure?bbox=-30,-70,-35,-65&kinds=health&zoom=6')
            ->assertUnprocessable();
        $this->getJson('/api/infrastructure?bbox=-95,-70,-92,-65&kinds=health&zoom=6')
            ->assertUnprocessable();
    }

    private function createFacilities(string $kind, int $count): void
    {
        $timestamp = now()->toDateTimeString();
        $records = [];
        for ($index = 1; $index <= $count; $index++) {
            $records[] = [
                'external_id' => $kind.'-'.$index,
                'kind' => $kind,
                'name' => $kind.' '.$index,
                'latitude' => -25.3,
                'longitude' => -57.6,
                'source_name' => 'INE fixture',
                'source_url' => 'https://example.test/infrastructure',
                'synced_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        foreach (array_chunk($records, 100) as $chunk) {
            CriticalFacility::query()->insert($chunk);
        }
    }
}
