<?php

use Fogeto\ServerOrchestrator\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

$routeConfig = config('server-orchestrator.routes', []);

if (! ($routeConfig['enabled'] ?? true)) {
    return;
}

$middleware = $routeConfig['middleware'] ?? [];

Route::group([
    'middleware' => $middleware,
], function () {
    Route::get('/metrics', [MetricsController::class, 'index']);
});
