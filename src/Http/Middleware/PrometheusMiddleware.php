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

        $method = $request->method();
        $endpoint = $this->resolveEndpoint($request);
        [$controller, $action] = $this->resolveControllerAction($request);
        $inProgressLabels = [$method, $controller, $action, $endpoint];

        $inProgressGauge = $this->registry->getOrRegisterGauge(
            'http',
            'requests_in_progress',
            'The number of HTTP requests currently in progress in the Laravel application.',
            ['method', 'controller', 'action', 'endpoint']
        );
        $inProgressGauge->inc($inProgressLabels);

        $start = microtime(true);

        try {
            $response = $next($request);
        } finally {
            $inProgressGauge->dec($inProgressLabels);
        }

        $duration = microtime(true) - $start;
        $code = (string) $response->getStatusCode();

        $buckets = config('server-orchestrator.histogram_buckets', [
            0.001, 0.002, 0.004, 0.008, 0.016, 0.032, 0.064, 0.128,
            0.256, 0.512, 1.024, 2.048, 4.096, 8.192, 16.384, 32.768,
        ]);

        // Request duration histogram
        $histogram = $this->registry->getOrRegisterHistogram(
            'http',
            'request_duration_seconds',
            'The duration of HTTP requests processed by the Laravel application.',
            ['code', 'method', 'controller', 'action', 'endpoint'],
            $buckets
        );
        $histogram->observe($duration, [$code, $method, $controller, $action, $endpoint]);

        $receivedCounter = $this->registry->getOrRegisterCounter(
            'http',
            'requests_received_total',
            'The total number of HTTP requests processed by the Laravel application.',
            ['code', 'method', 'controller', 'action', 'endpoint']
        );
        $receivedCounter->inc([$code, $method, $controller, $action, $endpoint]);

        return $response;
    }

    /**
     * Path'in ignore listesinde olup olmadığını kontrol et.
     * Wildcard (*) desteği sağlar: 'telescope/*', 'horizon/*'
     */
    private function shouldIgnore(string $path): bool
    {
        $ignorePaths = config('server-orchestrator.middleware.ignore_paths', [
            'metrics',
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
