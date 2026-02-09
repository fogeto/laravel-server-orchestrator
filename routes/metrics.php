<?php

use AysYazilim\ServerOrchestrator\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

$routeConfig = config('server-orchestrator.routes', []);

if (! ($routeConfig['enabled'] ?? true)) {
    return;
}

Route::group([
    'prefix' => $routeConfig['prefix'] ?? 'api',
    'middleware' => $routeConfig['middleware'] ?? [],
], function () {
    Route::get('/metrics', [MetricsController::class, 'index']);
    Route::post('/wipe-metrics', [MetricsController::class, 'wipe']);
});
