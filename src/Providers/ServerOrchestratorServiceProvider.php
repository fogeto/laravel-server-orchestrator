<?php

namespace Fogeto\ServerOrchestrator\Providers;

use Fogeto\ServerOrchestrator\Adapters\PredisAdapter;
use Fogeto\ServerOrchestrator\Console\Commands\MigrateFromInlineCommand;
use Fogeto\ServerOrchestrator\Helpers\SqlParser;
use Fogeto\ServerOrchestrator\Http\Middleware\PrometheusMiddleware;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
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

        // Middleware'i kaydet
        if (config('server-orchestrator.middleware.enabled', true)) {
            $this->registerMiddleware();
        }

        // SQL sorgu metriklerini kaydet (DB::listen)
        if (config('server-orchestrator.sql_metrics.enabled', true)) {
            $this->registerSqlListener();
        }
    }

    /**
     * DB::listen ile SQL sorgularını dinle ve histogram metriği olarak kaydet.
     *
     * Her sorgu için operation, table, query ve query_hash label'ları
     * ile sql_query_duration_seconds histogram'ına gözlem eklenir.
     */
    private function registerSqlListener(): void
    {
        DB::listen(function ($query) {
            $sql = $query->sql;

            // Yok sayılacak sorguları kontrol et (SHOW, SET, vb.)
            if ($this->shouldIgnoreSql($sql)) {
                return;
            }

            try {
                $registry = app(CollectorRegistry::class);
                $parsed = SqlParser::parse($sql);

                $buckets = config('server-orchestrator.sql_histogram_buckets', [
                    0.005,
                    0.01,
                    0.025,
                    0.05,
                    0.1,
                    0.25,
                    0.5,
                    1,
                    2.5,
                    5,
                    10,
                ]);

                // Label'lar: operation, table, query, query_hash
                $labelNames = ['operation', 'table', 'query_hash'];
                $labelValues = [
                    $parsed['operation'],
                    $parsed['table'],
                    $parsed['query_hash'],
                ];

                // Opsiyonel: query metnini label olarak ekle
                if (config('server-orchestrator.sql_metrics.include_query_label', true)) {
                    $maxLength = config('server-orchestrator.sql_metrics.query_max_length', 200);
                    $interpolated = SqlParser::interpolateBindings($sql, $query->bindings);
                    $sanitizedQuery = SqlParser::sanitizeForLabel($interpolated, $maxLength);

                    array_splice($labelNames, 2, 0, ['query']);
                    array_splice($labelValues, 2, 0, [$sanitizedQuery]);
                }

                $histogram = $registry->getOrRegisterHistogram(
                    'sql',
                    'query_duration_seconds',
                    'Duration of SQL queries in seconds.',
                    $labelNames,
                    $buckets
                );

                // DB::listen time değeri milisaniye cinsinden gelir → saniyeye çevir
                $durationInSeconds = $query->time / 1000;

                $histogram->observe($durationInSeconds, $labelValues);
            } catch (\Throwable $e) {
                // Metrik hatası uygulamayı kırmamalı — sessizce devam et
            }
        });
    }

    /**
     * SQL sorgusunun yok sayılıp sayılmayacağını kontrol et.
     *
     * Config'deki ignore_patterns regex listesiyle eşleştirir.
     */
    private function shouldIgnoreSql(string $sql): bool
    {
        $ignorePatterns = config('server-orchestrator.sql_metrics.ignore_patterns', [
            '/^SHOW\s/i',
            '/^SET\s/i',
            '/information_schema/i',
        ]);

        foreach ($ignorePatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                return true;
            }
        }

        return false;
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
