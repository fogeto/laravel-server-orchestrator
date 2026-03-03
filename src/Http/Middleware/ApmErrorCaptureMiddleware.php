<?php

namespace Fogeto\ServerOrchestrator\Http\Middleware;

use Closure;
use Fogeto\ServerOrchestrator\Services\ApmErrorBuffer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP hata response'larını yakalayıp Redis circular buffer'da tutan APM middleware.
 *
 * .NET'teki ApmErrorCaptureMiddleware'in Laravel karşılığı.
 *
 * Yakalanan status code'lar: 400, 401, 403, 404, 429, 500, 502, 503
 *
 * Her hata event'i şunları içerir:
 *   - Request body (max 32KB)
 *   - Response body (max 32KB)
 *   - Request/Response headers (hassas olanlar redact edilir)
 *   - Duration (ms)
 *   - Client IP (X-Forwarded-For desteği)
 *   - User-Agent
 *   - Query string
 *
 * Buffer: Redis List (LPUSH + LTRIM), max 200 event (circular — en eskiler otomatik silinir)
 * Endpoint: /__apm/errors veya /apm/errors (ApmController üzerinden)
 */
class ApmErrorCaptureMiddleware
{
    private ApmErrorBuffer $buffer;

    public function __construct(ApmErrorBuffer $buffer)
    {
        $this->buffer = $buffer;
    }

    public function handle(Request $request, Closure $next): Response
    {
        // APM ignore path'leri kontrol et
        if ($this->shouldIgnore($request->path())) {
            return $next($request);
        }

        $start = microtime(true);

        $response = $next($request);

        $statusCode = $response->getStatusCode();

        // Sadece belirli status code'ları yakala
        if ($this->buffer->shouldCapture($statusCode)) {
            $duration = (microtime(true) - $start) * 1000; // ms

            $this->captureError($request, $response, $duration);
        }

        return $response;
    }

    /**
     * Hata event'ini yakala ve buffer'a kaydet.
     */
    private function captureError(Request $request, Response $response, float $durationMs): void
    {
        try {
            $maxBodySize = config('server-orchestrator.apm.max_body_size', 32768);

            // Request body
            $requestBody = '';
            try {
                $content = $request->getContent();
                if ($content !== false && $content !== '') {
                    $requestBody = substr($content, 0, $maxBodySize);
                }
            } catch (\Throwable) {
                // Request body okunamadıysa boş bırak
            }

            // Response body
            $responseBody = '';
            try {
                $content = $response->getContent();
                if ($content !== false && $content !== '') {
                    $responseBody = substr($content, 0, $maxBodySize);
                }
            } catch (\Throwable) {
                // Response body okunamadıysa boş bırak
            }

            // Request headers
            $requestHeaders = [];
            foreach ($request->headers->all() as $key => $values) {
                $requestHeaders[$key] = is_array($values) ? implode(', ', $values) : (string) $values;
            }

            // Response headers
            $responseHeaders = [];
            foreach ($response->headers->all() as $key => $values) {
                $responseHeaders[$key] = is_array($values) ? implode(', ', $values) : (string) $values;
            }

            $this->buffer->captureIncoming([
                'path' => '/' . ltrim($request->path(), '/'),
                'method' => $request->method(),
                'statusCode' => $response->getStatusCode(),
                'requestBody' => $requestBody,
                'responseBody' => $responseBody,
                'requestHeaders' => $requestHeaders,
                'responseHeaders' => $responseHeaders,
                'durationMs' => $durationMs,
                'clientIp' => $this->resolveClientIp($request),
                'userAgent' => $request->userAgent() ?? '',
                'queryString' => $request->getQueryString() ?? '',
            ]);
        } catch (\Throwable) {
            // APM capture hatası asla request'i etkilememeli
        }
    }

    /**
     * Gerçek client IP'sini çöz — X-Forwarded-For desteği.
     */
    private function resolveClientIp(Request $request): string
    {
        // X-Forwarded-For: ilk IP = gerçek client IP (proxy zincirinde)
        $forwarded = $request->header('X-Forwarded-For');
        if (!empty($forwarded)) {
            $ips = explode(',', $forwarded);
            return trim($ips[0]);
        }

        return $request->ip() ?? 'unknown';
    }

    /**
     * Path'in ignore listesinde olup olmadığını kontrol et.
     */
    private function shouldIgnore(string $path): bool
    {
        $ignorePaths = config('server-orchestrator.apm.ignore_paths', [
            'api/metrics',
            'metrics',
            '__apm/*',
            'apm/*',
        ]);

        foreach ($ignorePaths as $ignorePath) {
            if ($path === $ignorePath) {
                return true;
            }

            if (str_contains($ignorePath, '*') && fnmatch($ignorePath, $path)) {
                return true;
            }
        }

        return false;
    }
}
