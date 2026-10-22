<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CommunityIncidentController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\LayerController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MonitoringStationController;
use App\Http\Controllers\Api\NearbyInfrastructureController;
use App\Http\Controllers\Api\ZoneNowController;
use App\Http\Controllers\Api\CriticalFacilityController;
use App\Http\Controllers\Api\FavoriteZoneController;
use App\Http\Controllers\Api\RoadController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:api')->group(function (): void {
    Route::get('/categories', CategoryController::class)->name('api.categories.index');
    Route::get('/locations', LocationController::class)->name('api.locations.index');
    Route::get('/layers', LayerController::class)->name('api.layers.index');
    Route::get('/nearby/infrastructure', NearbyInfrastructureController::class)->name('api.nearby.infrastructure');
    Route::get('/nearby/summary', ZoneNowController::class)->name('api.nearby.summary');
    Route::get('/infrastructure', CriticalFacilityController::class)->name('api.infrastructure.index');
    Route::get('/monitoring/stations', MonitoringStationController::class)->name('api.monitoring.stations');
    Route::get('/roads/resolve', [RoadController::class, 'resolve'])->name('api.roads.resolve');
    Route::get('/roads', [RoadController::class, 'index'])->name('api.roads.index');
    Route::get('/roads/{road}', [RoadController::class, 'show'])->whereNumber('road')->name('api.roads.show');
    Route::get('/roads/{road}/segments', [RoadController::class, 'segments'])->whereNumber('road')->name('api.roads.segments');
    Route::get('/roads/{road}/reference-points', [RoadController::class, 'referencePoints'])->whereNumber('road')->name('api.roads.reference-points');
    Route::get('/favorite-zones', [FavoriteZoneController::class, 'index'])->name('api.favorite-zones.index');
    Route::post('/favorite-zones', [FavoriteZoneController::class, 'store'])->name('api.favorite-zones.store');
    Route::put('/favorite-zones/{favoriteZone}', [FavoriteZoneController::class, 'update'])->whereNumber('favoriteZone')->name('api.favorite-zones.update');
    Route::delete('/favorite-zones/{favoriteZone}', [FavoriteZoneController::class, 'destroy'])->whereNumber('favoriteZone')->name('api.favorite-zones.destroy');
    Route::get('/favorite-zones/{favoriteZone}/status', [FavoriteZoneController::class, 'status'])->whereNumber('favoriteZone')->name('api.favorite-zones.status');
    Route::get('/stations', MonitoringStationController::class)->name('api.stations.index');
    Route::get('/stations/{station}', [MonitoringStationController::class, 'show'])->whereNumber('station')->name('api.stations.show');
    Route::get('/stations/{station}/measurements', [MonitoringStationController::class, 'measurements'])->whereNumber('station')->name('api.stations.measurements');

    Route::get('/incidents', [IncidentController::class, 'index'])->name('api.incidents.index');
    Route::post('/incidents', [CommunityIncidentController::class, 'store'])->name('api.community-incidents.store');
    Route::get('/incidents/updates', [IncidentController::class, 'updates'])->name('api.incidents.updates');
    Route::post('/incidents/{publicId}/vote', [CommunityIncidentController::class, 'vote'])
        ->whereUuid('publicId')
        ->name('api.community-incidents.vote');
    Route::get('/incidents/{publicId}/photo', [CommunityIncidentController::class, 'photo'])
        ->whereUuid('publicId')
        ->name('api.incidents.photo');
    Route::get('/incidents/{publicId}', [IncidentController::class, 'show'])
        ->whereUuid('publicId')
        ->name('api.incidents.show');
});
