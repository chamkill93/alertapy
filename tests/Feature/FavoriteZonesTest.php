<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\LocationAccuracy;
use App\Enums\Severity;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Incident;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteZonesTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_owner_can_create_update_query_and_delete_a_zone(): void
    {
        $this->seed(CategorySeeder::class); $category = Category::query()->where('slug','electricity')->firstOrFail();
        Incident::query()->create(['category_id'=>$category->id,'title'=>'Corte real','summary'=>'Corte','source_type'=>SourceType::Official,'verification_level'=>'official','status'=>IncidentStatus::Active,'severity'=>Severity::Medium,'latitude'=>-25.3,'longitude'=>-57.6,'location_accuracy'=>LocationAccuracy::Exact,'location_text'=>'Central','published_at'=>now(),'expires_at'=>now()->addHour()]);
        $created = $this->postJson('/api/favorite-zones', ['name'=>'Casa','icon'=>'home','latitude'=>-25.3,'longitude'=>-57.6,'radius_km'=>3,'electricity_enabled'=>true,'water_enabled'=>false]);
        $created->assertCreated()->assertJsonPath('data.name','Casa'); $token = $created->getCookie('alertapy_favorite_token', false)->getValue(); $id = $created->json('data.id');
        $this->withHeader('X-AlertaPY-Favorite-Token',$token)->getJson('/api/favorite-zones')->assertOk()->assertJsonCount(1,'data');
        $this->withHeader('X-AlertaPY-Favorite-Token',$token)->getJson("/api/favorite-zones/{$id}/status")->assertOk()->assertJsonPath('data.incident_count',1)->assertJsonPath('data.activity','ACTIVIDAD BAJA');
        $this->withHeader('X-AlertaPY-Favorite-Token',$token)->putJson("/api/favorite-zones/{$id}", ['name'=>'Trabajo','radius_km'=>5])->assertOk()->assertJsonPath('data.name','Trabajo');
        $this->withHeaders(['X-AlertaPY-Favorite-Token'=>'invalid'])->getJson("/api/favorite-zones/{$id}/status")->assertNotFound();
        $this->withHeader('X-AlertaPY-Favorite-Token',$token);
        $this->withHeader('X-AlertaPY-Favorite-Token',$token)->deleteJson("/api/favorite-zones/{$id}")->assertNoContent(); $this->assertDatabaseCount('favorite_zones',0);
    }

    public function test_radius_and_paraguay_bounds_are_validated(): void
    {
        $this->postJson('/api/favorite-zones',['name'=>'Fuera','latitude'=>0,'longitude'=>0,'radius_km'=>100])->assertUnprocessable()->assertJsonValidationErrors(['latitude','longitude','radius_km']);
    }
}
