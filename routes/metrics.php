<?php

use Fogeto\ServerOrchestrator\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

$routeConfig = config('server-orchestrator.routes', []);

if (! ($routeConfig['enabled'] ?? true)) {
    return;
}

$prefix = trim((string) ($routeConfig['prefix'] ?? 'api'), '/');
$middleware = $routeConfig['middleware'] ?? [];

$registerMetricsRoutes = static function (array $attributes): void {
    Route::group($attributes, function () {
        Route::get('/metrics', [MetricsController::class, 'index']);
        Route::post('/wipe-metrics', [MetricsController::class, 'wipe']);
    });
};

$groupAttributes = ['middleware' => $middleware];
if ($prefix !== '') {
    $groupAttributes['prefix'] = $prefix;
}

$registerMetricsRoutes($groupAttributes);

if ($prefix !== '') {
    $registerMetricsRoutes(['middleware' => $middleware]);
}
