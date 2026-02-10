<?php

namespace Fogeto\ServerOrchestrator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Prometheus\CollectorRegistry;
use Symfony\Component\HttpFoundation\Response;

class PrometheusMiddleware
{
    public function __construct(private CollectorRegistry $registry) {}

    /**
     * Handle: Sadece başlangıç zamanını kaydet ve isteği ilerlet.
     *
     * Metrik yazma işlemleri terminate() aşamasında yapılır, böylece
     * response client'a gönderildikten sonra Redis'e yazılır (performans).
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Yok sayılacak path'leri kontrol et
        if ($this->shouldIgnore($request->path())) {
            return $next($request);
        }

        // Başlangıç zamanını request attribute olarak sakla
        $request->attributes->set('_prometheus_start_time', microtime(true));

        return $next($request);
    }

    /**
     * Terminate: Response gönderildikten sonra metrikleri Redis'e kaydet.
     *
     * Bu metod HTTP kernel tarafından response client'a iletildikten sonra çağrılır.
     * Böylece metrik yazma gecikmesi kullanıcıya yansımaz.
     */
    public function terminate(Request $request, Response $response): void
    {
        $start = $request->attributes->get('_prometheus_start_time');

        if ($start === null) {
            return;
        }

        $duration = microtime(true) - $start;
        $method = $request->method();
        $code = (string) $response->getStatusCode();
        $endpoint = $this->resolveEndpoint($request);
        [$controller, $action] = $this->resolveControllerAction($request);

        $buckets = config('server-orchestrator.http_histogram_buckets', [
            0.001,
            0.005,
            0.01,
            0.05,
            0.1,
            0.5,
            1,
            5,
        ]);

        try {
            // HTTP request duration histogram
            $histogram = $this->registry->getOrRegisterHistogram(
                'http',
                'request_duration_seconds',
                'The duration of HTTP requests processed by a Laravel application.',
                ['code', 'method', 'controller', 'action', 'endpoint'],
                $buckets
            );
            $histogram->observe($duration, [$code, $method, $controller, $action, $endpoint]);

            // Total requests counter
            $counter = $this->registry->getOrRegisterCounter(
                'http',
                'requests_total',
                'Total number of HTTP requests.',
                ['code', 'method', 'controller', 'action', 'endpoint']
            );
            $counter->inc([$code, $method, $controller, $action, $endpoint]);

            // Error counter (4xx ve 5xx)
            if ($response->getStatusCode() >= 400) {
                $errorCounter = $this->registry->getOrRegisterCounter(
                    'http',
                    'errors_total',
                    'Total number of HTTP errors (4xx and 5xx).',
                    ['code', 'method', 'controller', 'action', 'endpoint']
                );
                $errorCounter->inc([$code, $method, $controller, $action, $endpoint]);
            }
        } catch (\Throwable $e) {
            // Metrik hatası uygulamayı kırmamalı — sessizce devam et
        }
    }

    /**
     * Path'in ignore listesinde olup olmadığını kontrol et.
     * Wildcard (*) desteği sağlar: 'telescope/*', 'horizon/*'
     */
    private function shouldIgnore(string $path): bool
    {
        $ignorePaths = config('server-orchestrator.middleware.ignore_paths', [
            'api/metrics',
            'metrics',
            'api/wipe-metrics',
            'wipe-metrics',
        ]);

        foreach ($ignorePaths as $ignorePath) {
            if ($path === $ignorePath) {
                return true;
            }

            // Wildcard desteği
            if (str_contains($ignorePath, '*') && fnmatch($ignorePath, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Route'dan controller ve action bilgisini çıkar.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveControllerAction(Request $request): array
    {
        $route = $request->route();

        if (! $route) {
            return ['', ''];
        }

        $action = $route->getAction();

        // Controller@method formatı
        if (isset($action['controller'])) {
            $parts = explode('@', $action['controller']);

            if (count($parts) === 2) {
                $controllerName = class_basename($parts[0]);
                $method = $parts[1];

                return [$controllerName, $method];
            }
        }

        // Closure route (anonim fonksiyon)
        return ['', ''];
    }

    /**
     * Endpoint path'ini normalize et — ID'leri parametrelere dönüştür.
     */
    private function resolveEndpoint(Request $request): string
    {
        $route = $request->route();

        // Route varsa tanımlı URI'yi kullan (zaten parametreli: api/users/{id})
        if ($route) {
            $uri = $route->uri();

            return str_starts_with($uri, '/') ? $uri : '/' . $uri;
        }

        // Route bulunamadıysa path'i normalize et
        $path = $request->path();

        // UUID'leri normalize et
        $path = preg_replace(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            '{uuid}',
            $path
        );

        // Sayısal ID'leri normalize et
        $path = preg_replace('/\/\d+/', '/{id}', $path);

        return '/' . ($path ?: '');
    }
}
