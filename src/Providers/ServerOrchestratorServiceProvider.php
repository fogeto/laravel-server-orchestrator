<?php

namespace Fogeto\ServerOrchestrator\Providers;

use Fogeto\ServerOrchestrator\Adapters\PredisAdapter;
use Fogeto\ServerOrchestrator\Console\Commands\MigrateFromInlineCommand;
use Fogeto\ServerOrchestrator\Helpers\SqlParser;
use Fogeto\ServerOrchestrator\Http\Middleware\PrometheusMiddleware;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;

class ServerOrchestratorServiceProvider extends ServiceProvider
{
    /**
     * SQL observation buffer — istek boyunca biriktirilir, sonunda flush edilir.
     * Her giriş: ['duration' => float, 'labels' => array]
     *
     * @var array<int, array{duration: float, labels: array}>
     */
    private static array $sqlBuffer = [];

    /**
     * Buffer flush'ı zaten kayıtlı mı?
     */
    private static bool $flushRegistered = false;

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

                $adapter = new PredisAdapter($redisConnection, $prefix, config('server-orchestrator.metrics_ttl', 604800));
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

        // HTTP Middleware'i kaydet
        if (config('server-orchestrator.middleware.enabled', true)) {
            $this->registerMiddleware();
        }

        // SQL Listener — DB::listen() ile sorgu metriklerini topla
        if (config('server-orchestrator.sql_metrics.enabled', true)) {
            $this->registerSqlListener();
        }
    }

    /**
     * SQL sorgu dinleyicisini kaydet.
     *
     * Performans optimizasyonu: Sorgular istek boyunca statik buffer'a biriktirilir,
     * istek sonunda (app terminating) topluca Redis'e flush edilir.
     * Bu sayede N sorgu = 1 Redis pipeline round-trip (N ayrı round-trip yerine).
     */
    private function registerSqlListener(): void
    {
        $ignorePatterns = config('server-orchestrator.sql_metrics.ignore_patterns', []);
        $includeQuery = config('server-orchestrator.sql_metrics.include_query_label', false);
        $maxLength = config('server-orchestrator.sql_metrics.query_max_length', 200);

        DB::listen(function (QueryExecuted $query) use ($ignorePatterns, $includeQuery, $maxLength) {
            $sql = $query->sql;

            // Ignore patterns — gereksiz sorguları filtrele
            foreach ($ignorePatterns as $pattern) {
                if (preg_match($pattern, $sql)) {
                    return;
                }
            }

            // Parse SQL — operation, table, query_hash
            $parsed = SqlParser::parse($sql);

            // Duration: QueryExecuted->time milisaniye, Prometheus saniye ister
            $duration = $query->time / 1000;

            // Label'lar
            $labels = [
                $parsed['operation'],
                $parsed['table'],
                $parsed['query_hash'],
            ];

            // Opsiyonel query label (dikkat: yüksek cardinality)
            if ($includeQuery) {
                $labels[] = SqlParser::sanitizeForLabel($sql, $maxLength);
            }

            // Buffer'a ekle — flush sonra yapılacak
            self::$sqlBuffer[] = [
                'duration' => $duration,
                'labels' => $labels,
            ];

            // Terminating callback'i sadece bir kez kaydet
            if (! self::$flushRegistered) {
                self::$flushRegistered = true;
                app()->terminating(function () {
                    self::flushSqlMetrics();
                });
            }
        });
    }

    /**
     * Birikmiş SQL metriklerini topluca Redis'e yaz.
     *
     * Tek seferde tüm observe'ları yapar. PredisAdapter'ın pipeline
     * optimizasyonu ile birlikte çalışır.
     */
    private static function flushSqlMetrics(): void
    {
        if (empty(self::$sqlBuffer)) {
            return;
        }

        try {
            $registry = app(CollectorRegistry::class);

            $includeQuery = config('server-orchestrator.sql_metrics.include_query_label', false);
            $labelNames = ['operation', 'table', 'query_hash'];
            if ($includeQuery) {
                $labelNames[] = 'query';
            }

            $buckets = config('server-orchestrator.sql_metrics.histogram_buckets', [
                0.001, 0.005, 0.01, 0.025, 0.05, 0.1,
                0.25, 0.5, 1.0, 2.5, 5.0, 10.0,
            ]);

            $histogram = $registry->getOrRegisterHistogram(
                'sql',
                'query_duration_seconds',
                'Duration of SQL queries in seconds.',
                $labelNames,
                $buckets
            );

            foreach (self::$sqlBuffer as $observation) {
                $histogram->observe($observation['duration'], $observation['labels']);
            }
        } catch (\Throwable $e) {
            // Sessizce devam et — uygulama crash olmamalı
        } finally {
            // Buffer'ı temizle (Octane/Swoole uyumluluğu)
            self::$sqlBuffer = [];
            self::$flushRegistered = false;
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
