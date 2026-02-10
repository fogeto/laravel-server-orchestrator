<?php

namespace Fogeto\ServerOrchestrator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Prometheus\CollectorRegistry;
use Symfony\Component\HttpFoundation\Response;

class PrometheusMiddleware
{
    public function __construct(private CollectorRegistry $registry) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Yok sayılacak path'leri kontrol et
        if ($this->shouldIgnore($request->path())) {
            return $next($request);
        }

        $start = microtime(true);

        $response = $next($request);

        $duration = microtime(true) - $start;
        $method = $request->method();
        $code = (string) $response->getStatusCode();
        $endpoint = $this->resolveEndpoint($request);
        [$controller, $action] = $this->resolveControllerAction($request);

        $buckets = config('server-orchestrator.histogram_buckets', [
            0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5,
            1.0, 2.5, 5.0, 10.0, 30.0,
        ]);

        // Request duration histogram
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

        return $response;
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
