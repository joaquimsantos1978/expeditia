<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ExpeditionController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\InstitutionController;

// Public views
Route::get('/', [ExpeditionController::class, 'index'])->name('expeditions.index');
Route::get('/expedition/{qid}', [ExpeditionController::class, 'show'])->name('expeditions.show');
Route::get('/people', [PersonController::class, 'index'])->name('people.index');
Route::get('/person/{qid}', [PersonController::class, 'show'])->name('people.show');

// Institution pages
Route::get('/institutions',                   [InstitutionController::class, 'index'])->name('institutions.index');
Route::get('/institution/{code}',             [InstitutionController::class, 'show'])->name('institutions.show');
Route::get('/institution/{code}/map-data',    [InstitutionController::class, 'mapData'])->name('institutions.map-data');
Route::get('/institution/{code}/item/{id}',   [InstitutionController::class, 'itemDetail'])->name('institutions.item');
Route::get('/institution/{code}/export/csv',  [InstitutionController::class, 'exportCsv'])->name('institutions.export.csv');
Route::get('/institution/{code}/export/json', [InstitutionController::class, 'exportJson'])->name('institutions.export.json');

// Expedition exports
Route::get('/expedition/{qid}/export/csv',  [ExpeditionController::class, 'exportCsv'])->name('expeditions.export.csv');
Route::get('/expedition/{qid}/export/json', [ExpeditionController::class, 'exportJson'])->name('expeditions.export.json');

Route::get('/expedition/{qid}/map-data', [ExpeditionController::class, 'mapData'])->name('expeditions.map-data');
Route::get('/expedition/{qid}/item/{id}', [ExpeditionController::class, 'itemDetail'])->name('expeditions.item');

// Person exports
Route::get('/person/{qid}/export/csv',  [PersonController::class, 'exportCsv'])->name('people.export.csv');
Route::get('/person/{qid}/export/json', [PersonController::class, 'exportJson'])->name('people.export.json');

Route::get('/person/{qid}/map-data', [PersonController::class, 'personMapData'])->name('people.map-data');
Route::get('/person/{qid}/item/{id}', [PersonController::class, 'personItemDetail'])->name('people.item');

// API
Route::prefix('api')->group(function () {
    Route::get('/expeditions',            [ApiController::class, 'expeditions'])->name('api.expeditions');
    Route::get('/expedition/{qid}',       [ApiController::class, 'expedition'])->name('api.expedition');
    Route::get('/expedition/{qid}/items', [ApiController::class, 'items'])->name('api.expedition.items');
    Route::get('/person/{qid}',           [ApiController::class, 'person'])->name('api.person');
    Route::get('/person/{qid}/specimens', [ApiController::class, 'personSpecimens'])->name('api.person.specimens');
    Route::get('/institution/{code}',          [ApiController::class, 'institution'])->name('api.institution');
    Route::get('/institution/{code}/specimens',[ApiController::class, 'institutionSpecimens'])->name('api.institution.specimens');
});

// Sync
Route::get('/sync/reindex',                  [SyncController::class, 'reindexAll'])->name('sync.reindex');
Route::get('/sync/expeditions',              [SyncController::class, 'syncExpeditionList'])->name('sync.expeditions');
Route::get('/sync/expedition/{id}/meta',     [SyncController::class, 'syncExpeditionMeta'])->name('sync.expedition.meta');
Route::get('/sync/expedition/{id}/points',   [SyncController::class, 'syncExpeditionPoints'])->name('sync.expedition.points');
Route::get('/sync/expedition/{id}/gbif',     [SyncController::class, 'syncExpeditionGbif'])->name('sync.expedition.gbif');
Route::get('/sync/expedition/{id}/georeference', [SyncController::class, 'syncExpeditionGeoreference'])->name('sync.expedition.georeference');
Route::get('/sync/expedition/{id}/bionomia', [SyncController::class, 'syncExpeditionBionomia'])->name('sync.expedition.bionomia');
Route::get('/sync/expedition/{id}/gbif-enrich', [SyncController::class, 'syncExpeditionGbifEnrich'])->name('sync.expedition.gbif-enrich');
Route::get('/sync/resync/{qid}',             [SyncController::class, 'resyncExpedition'])->name('sync.resync');
Route::get('/sync/specimen/{gbifId}/refresh', [SyncController::class, 'refreshSpecimen'])
    ->where('gbifId', '[0-9]+')
    ->middleware('throttle:20,1')
    ->name('sync.specimen.refresh');
Route::get('/sync/add/{qid}',                [SyncController::class, 'addExpedition'])->name('sync.add');
Route::get('/sync/person/{id}',              [SyncController::class, 'syncPerson'])->name('sync.person');
Route::get('/sync/cron',                     [SyncController::class, 'cronSync'])->name('sync.cron');

// Refresh institution stats + GBIF metadata (run after sync or on demand)
// Optional params: ?limit=20&stale_days=30
Route::get('/sync/institutions', [SyncController::class, 'syncInstitutions'])->name('sync.institutions');

Route::get('/sync/resync/{qid}/stream',   [SyncController::class, 'resyncStream'])       ->name('sync.resync.stream');
Route::get('/sync/person/{qid}/stream',   [SyncController::class, 'resyncPersonStream']) ->name('sync.person.stream');

Route::get('/about',       [PageController::class, 'about'])     ->name('about');
Route::get('/developers',  [PageController::class, 'developers']) ->name('about.developers');

// Utilities
Route::get('/clear', function() {
    \Artisan::call('config:clear');
    \Artisan::call('route:clear');
    \Artisan::call('view:clear');
    \Artisan::call('cache:clear');
    if (function_exists('opcache_reset')) opcache_reset();
    return 'Cache cleared!';
});
