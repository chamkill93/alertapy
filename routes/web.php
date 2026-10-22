<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BackofficeController;
use App\Http\Controllers\Admin\ConnectionController;
use App\Http\Controllers\Admin\CoverageController;
use App\Http\Controllers\Admin\NewsController;
use App\Http\Controllers\Admin\RoadNetworkController;
use App\Http\Controllers\Api\SourceStatusController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\RoadPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', MapController::class)->name('map');
Route::get('/rutas/{code}', RoadPageController::class)->where('code', '[A-Za-z0-9-]+')->name('roads.show');
Route::prefix('admin')->name('admin.')->middleware('admin.private')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
    Route::get('/setup', [AuthController::class, 'setup'])->name('setup');
    Route::post('/setup', [AuthController::class, 'setupStore'])->name('setup.store');

    Route::middleware('admin')->group(function (): void {
        Route::get('/', BackofficeController::class)->name('dashboard');
        Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
        Route::get('/news', [NewsController::class, 'index'])->name('news.index');
        Route::get('/news/{rawItem}', [NewsController::class, 'show'])->whereNumber('rawItem')->name('news.show');
        Route::get('/connections', [ConnectionController::class, 'index'])->name('connections.index');
        Route::get('/connections/{source}', [ConnectionController::class, 'show'])->whereNumber('source')->name('connections.show');
    });

    Route::middleware('admin:legacy')->group(function (): void {
        Route::get('/coverage', CoverageController::class)->name('coverage');
        Route::get('/roads', [RoadNetworkController::class, 'index'])->name('roads.index');
        Route::post('/roads', [RoadNetworkController::class, 'store'])->name('roads.store');
        Route::post('/roads/reprocess', [RoadNetworkController::class, 'reprocess'])->name('roads.reprocess');
        Route::post('/roads/{roadDataset}/deactivate', [RoadNetworkController::class, 'deactivate'])->name('roads.deactivate');
    });
});

// Source diagnostics require an administrative session, including JSON access.
Route::middleware(['admin.private', 'admin', 'throttle:api'])->group(function (): void {
    Route::get('/api/sources/status', SourceStatusController::class)->name('api.sources.status');
    Route::get('/api/sources', SourceStatusController::class)->name('api.sources.index');
});
