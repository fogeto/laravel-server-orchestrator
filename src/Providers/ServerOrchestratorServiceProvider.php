<?php

namespace Fogeto\ServerOrchestrator\Providers;

use Fogeto\ServerOrchestrator\Adapters\PredisAdapter;
use Fogeto\ServerOrchestrator\Http\Middleware\PrometheusMiddleware;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;
use Prometheus\CollectorRegistry;
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

        $this->app->singleton(CollectorRegistry::class, function () {
            try {
                $connection = config('server-orchestrator.redis_connection', 'default');
                $redisConnection = Redis::connection($connection);

                $rawPrefix = config('server-orchestrator.prefix', 'laravel');
                $sanitized = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '_', $rawPrefix));
                $prefix = 'prometheus:' . $sanitized . ':';

                $adapter = new PredisAdapter($redisConnection, $prefix);
            } catch (\Throwable $e) {
                report($e);
                $adapter = new InMemory();
            }

            return new CollectorRegistry($adapter);
        });
    }

    public function boot(): void
    {
        // Config publish
        $this->publishes([
            __DIR__ . '/../../config/server-orchestrator.php' => config_path('server-orchestrator.php'),
        ], 'server-orchestrator-config');

        if (! config('server-orchestrator.enabled', true)) {
            return;
        }

        // Route'ları kaydet
        if (config('server-orchestrator.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/metrics.php');
        }

        // Middleware'i Kernel üzerinden pushMiddleware ile global middleware olarak ekle
        // veya router grubuna ekle — booted callback ile zamanlamayı doğru ayarla
        if (config('server-orchestrator.middleware.enabled', true)) {
            $this->registerMiddleware();
        }
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
                if (method_exists($kernel, 'appendMiddlewareToGroup')) {
                    $kernel->appendMiddlewareToGroup($group, PrometheusMiddleware::class);
                } elseif (method_exists($kernel, 'pushMiddleware')) {
                    // Fallback: global middleware olarak ekle
                    $kernel->pushMiddleware(PrometheusMiddleware::class);
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
}
