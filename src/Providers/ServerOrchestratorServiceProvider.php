<?php

namespace Fogeto\ServerOrchestrator\Providers;

use Fogeto\ServerOrchestrator\Adapters\PredisAdapter;
use Fogeto\ServerOrchestrator\Console\Commands\MigrateFromInlineCommand;
use Fogeto\ServerOrchestrator\Contracts\IApmErrorStore;
use Fogeto\ServerOrchestrator\Http\Middleware\ApmErrorCaptureMiddleware;
use Fogeto\ServerOrchestrator\Http\Middleware\PrometheusMiddleware;
use Fogeto\ServerOrchestrator\Listeners\HttpClientListener;
use Fogeto\ServerOrchestrator\Services\ApmErrorBuffer;
use Fogeto\ServerOrchestrator\Services\MongoApmErrorStore;
use Fogeto\ServerOrchestrator\Services\SqlQueryMetricsRecorder;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;

class ServerOrchestratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/server-orchestrator.php',
            'server-orchestrator'
        );

        if (! config('server-orchestrator.enabled', true)) {
            return;
        }

        $this->app->singleton(IApmErrorStore::class, function () {
            return new MongoApmErrorStore();
        });

        $this->app->singleton(ApmErrorBuffer::class, function ($app) {
            return new ApmErrorBuffer($app->make(IApmErrorStore::class));
        });

        $this->app->singleton(CollectorRegistry::class, function () {
            return new CollectorRegistry($this->makeMetricsAdapter());
        });

        $this->app->singleton(SqlQueryMetricsRecorder::class, function ($app) {
            return new SqlQueryMetricsRecorder($app->make(CollectorRegistry::class));
        });
    }

    private function makeMetricsAdapter(): Adapter
    {
        if (config('server-orchestrator.metrics_storage', 'redis') === 'in_memory') {
            return new InMemory();
        }

        try {
            $connection = config('server-orchestrator.redis_connection', 'default');
            $redisConnection = Redis::connection($connection);

            $rawPrefix = config('server-orchestrator.prefix', 'laravel');
            $sanitized = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '_', $rawPrefix));
            $prefix = 'prometheus:' . $sanitized . ':';

            return new PredisAdapter($redisConnection, $prefix, config('server-orchestrator.metrics_ttl', 86400));
        } catch (\Throwable $e) {
            report($e);

            return new InMemory();
        }
    }

    public function boot(): void
    {
        // Config publish
        $this->publishes([
            __DIR__ . '/../../config/server-orchestrator.php' => config_path('server-orchestrator.php'),
        ], 'server-orchestrator-config');

        // Artisan komutlarını kaydet
        if ($this->app->runningInConsole()) {
            $this->commands([
                MigrateFromInlineCommand::class,
            ]);
        }

        if (! config('server-orchestrator.enabled', true)) {
            return;
        }

        // Route'ları kaydet
        if (config('server-orchestrator.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/metrics.php');
        }

        // HTTP Middleware'i kaydet
        if (config('server-orchestrator.middleware.enabled', true)) {
            $this->registerMiddleware();
        }

        // SQL Listener — DB::listen() ile sorgu metriklerini topla
        if (config('server-orchestrator.sql_metrics.enabled', true)) {
            $this->registerSqlListener();
            $this->registerSqlExceptionReporter();
        }

        // HTTP Client Listener — outgoing HTTP isteklerini izle
        if (config('server-orchestrator.http_client_metrics.enabled', true)) {
            $this->registerHttpClientListener();
        }

        // APM Error Capture — hata response'larını yakala
        if (config('server-orchestrator.apm.enabled', true)) {
            $this->registerApmMiddleware();
            $this->registerApmRoutes();
        }
    }

    /**
     * SQL sorgu dinleyicisini kaydet.
     *
     * Her SQL sorgusu seçili metrics driver'ına anında yazılır.
     */
    private function registerSqlListener(): void
    {
        DB::listen(function (QueryExecuted $query) {
            app(SqlQueryMetricsRecorder::class)->recordDuration($query->sql, $query->time / 1000);
        });
    }

    /**
     * QueryException'ları exception handler seviyesinde dinle.
     */
    private function registerSqlExceptionReporter(): void
    {
        $register = function ($handler): void {
            $this->attachSqlExceptionReporter($handler);
        };

        if ($this->app->resolved(ExceptionHandlerContract::class)) {
            $register($this->app->make(ExceptionHandlerContract::class));
        }

        $this->app->afterResolving(ExceptionHandlerContract::class, $register);
    }

    private function attachSqlExceptionReporter(mixed $handler): void
    {
        static $attached = false;

        if ($attached || ! is_object($handler) || ! method_exists($handler, 'reportable')) {
            return;
        }

        $handler->reportable(function (QueryException $exception): void {
            app(SqlQueryMetricsRecorder::class)->recordError($exception->getSql());
        });

        $attached = true;
    }

    /**
     * HTTP Client event listener'larını kaydet.
     *
     * Laravel'in Http:: client'ı (Guzzle wrapper) ile yapılan outgoing
     * HTTP isteklerini izler. 3 event dinlenir:
     *   - RequestSending: Start time kaydet
     *   - ResponseReceived: Duration hesapla, metrik yaz
     *   - ConnectionFailed: Connection error metriği yaz
     *
     * Üretilen metrikler:
     *   - http_client_request_duration_seconds (Histogram)
     *   - http_client_requests_total (Counter)
     *   - http_client_errors_total (Counter — 4xx/5xx/connection error)
     */
    private function registerHttpClientListener(): void
    {
        $listener = new HttpClientListener();

        Event::listen(RequestSending::class, [$listener, 'handleRequestSending']);
        Event::listen(ResponseReceived::class, [$listener, 'handleResponseReceived']);
        Event::listen(ConnectionFailed::class, [$listener, 'handleConnectionFailed']);
    }

    /**
     * APM Error Capture Middleware'i kaydet.
     * PrometheusMiddleware ile aynı gruplara eklenir.
     * Sıralama: PrometheusMiddleware'den sonra çalışmalı.
     */
    private function registerApmMiddleware(): void
    {
        $groups = config('server-orchestrator.middleware.groups', ['api']);

        if ($this->app->bound(Kernel::class)) {
            $kernel = $this->app->make(Kernel::class);

            foreach ($groups as $group) {
                if ($this->appendMiddlewareToGroup($kernel, $group, ApmErrorCaptureMiddleware::class)) {
                    continue;
                }

                if ($this->pushGlobalMiddleware($kernel, ApmErrorCaptureMiddleware::class)) {
                    break;
                }
            }
        }

        $router = $this->app->make(\Illuminate\Routing\Router::class);

        foreach ($groups as $group) {
            $router->pushMiddlewareToGroup($group, ApmErrorCaptureMiddleware::class);
        }
    }

    /**
     * APM hata endpoint route'larını kaydet.
     * /__apm/errors ve /apm/errors endpoint'leri
     */
    private function registerApmRoutes(): void
    {
        $this->app->booted(function () {
            $router = $this->app->make(\Illuminate\Routing\Router::class);

            $router->get('/__apm/errors', [\Fogeto\ServerOrchestrator\Http\Controllers\ApmController::class, 'index']);
            $router->get('/apm/errors', [\Fogeto\ServerOrchestrator\Http\Controllers\ApmController::class, 'index']);
        });
    }

    /**
     * Middleware'i kaydet — Laravel 9-12 uyumlu.
     * HTTP Kernel'e doğrudan middleware push ederek zamanlama sorununu çözer.
     */
    private function registerMiddleware(): void
    {
        $groups = config('server-orchestrator.middleware.groups', ['api']);

        // Kernel üzerinden middleware grubuna ekle
        if ($this->app->bound(Kernel::class)) {
            $kernel = $this->app->make(Kernel::class);

            foreach ($groups as $group) {
                if ($this->appendMiddlewareToGroup($kernel, $group, PrometheusMiddleware::class)) {
                    continue;
                }

                if ($this->pushGlobalMiddleware($kernel, PrometheusMiddleware::class)) {
                    break;
                }
            }
        }

        // Router üzerinden de ekle (ek güvenlik)
        $router = $this->app->make(\Illuminate\Routing\Router::class);

        foreach ($groups as $group) {
            $router->pushMiddlewareToGroup($group, PrometheusMiddleware::class);
        }
    }

    private function appendMiddlewareToGroup(mixed $kernel, string $group, string $middleware): bool
    {
        if (! is_object($kernel) || ! method_exists($kernel, 'appendMiddlewareToGroup')) {
            return false;
        }

        $kernel->appendMiddlewareToGroup($group, $middleware);

        return true;
    }

    private function pushGlobalMiddleware(mixed $kernel, string $middleware): bool
    {
        if (! is_object($kernel) || ! method_exists($kernel, 'pushMiddleware')) {
            return false;
        }

        $kernel->pushMiddleware($middleware);

        return true;
    }
}
