<?php

namespace Fogeto\ServerOrchestrator\Http\Middleware;

use Closure;
use Fogeto\ServerOrchestrator\Services\ApmErrorBuffer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP hata response'larını yakalayıp APM store'a bırakan middleware.
 *
 * Capture kuralları:
 *   - Sadece belirli 4xx/5xx status code'ları
 *   - 5MB üstü veya multipart upload isteklerinde bypass
 *   - APM endpoint path'leri ignore edilir
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
        if ($this->shouldIgnore($request->path())) {
            return $next($request);
        }

        if ($this->shouldBypass($request)) {
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

    private function shouldBypass(Request $request): bool
    {
        $threshold = (int) config('server-orchestrator.apm.bypass_threshold_bytes', 5 * 1024 * 1024);
        $contentLength = $request->header('Content-Length');
        if ($contentLength !== null && (int) $contentLength > $threshold) {
            return true;
        }

        $contentType = $request->header('Content-Type', '');

        return str_starts_with(strtolower($contentType), 'multipart/form-data');
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
