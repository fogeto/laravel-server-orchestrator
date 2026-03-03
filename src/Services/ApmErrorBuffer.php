<?php

namespace Fogeto\ServerOrchestrator\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Redis-backed circular buffer — APM hata event'lerini saklar.
 *
 * .NET'teki ConcurrentQueue<ApmErrorEvent> yapısının Redis karşılığı.
 * Laravel birden fazla worker/process çalıştırdığında tüm süreçler
 * aynı buffer'ı görebilir.
 *
 * Redis List (LPUSH + LTRIM) ile circular buffer oluşturulur.
 * Max buffer size: config ile ayarlanabilir (varsayılan 200).
 *
 * Yakalanan status code'lar: 400, 401, 403, 404, 429, 500, 502, 503
 */
class ApmErrorBuffer
{
    /**
     * Error type label'ları — status code'dan insan okunabilir tipe çevir.
     */
    private const ERROR_TYPES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    /**
     * Yakalaması gereken status code'lar — O(1) lookup.
     */
    private const CAPTURED_STATUS_CODES = [400, 401, 403, 404, 429, 500, 502, 503];

    /**
     * Hassas header'lar — redact edilecek.
     */
    private const SENSITIVE_HEADERS = [
        'authorization',
        'cookie',
        'x-api-key',
        'x-csrf-token',
        'set-cookie',
    ];

    /**
     * Redis key.
     */
    private string $redisKey;

    /**
     * Max buffer size.
     */
    private int $maxBufferSize;

    /**
     * Max body capture size (bytes).
     */
    private int $maxBodySize;

    /**
     * Max message length.
     */
    private int $maxMessageLength;

    /**
     * İlk hata loglandı mı? Flood önleme.
     */
    private static bool $errorReported = false;

    public function __construct()
    {
        $prefix = config('server-orchestrator.prefix', 'laravel');
        $sanitized = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '_', $prefix));
        $this->redisKey = 'apm:' . $sanitized . ':errors';
        $this->maxBufferSize = config('server-orchestrator.apm.max_buffer_size', 200);
        $this->maxBodySize = config('server-orchestrator.apm.max_body_size', 32768); // 32KB
        $this->maxMessageLength = config('server-orchestrator.apm.max_message_length', 200);
    }

    /**
     * Verilen status code yakalanmalı mı?
     */
    public function shouldCapture(int $statusCode): bool
    {
        return in_array($statusCode, self::CAPTURED_STATUS_CODES, true);
    }

    /**
     * Status code'dan error type label'ı al.
     */
    public function getErrorType(int $statusCode): string
    {
        return self::ERROR_TYPES[$statusCode] ?? 'HTTP ' . $statusCode;
    }

    /**
     * Incoming (iç servis) hata event'i kaydet.
     */
    public function captureIncoming(array $data): void
    {
        $event = [
            'id' => (string) Str::uuid(),
            'timestamp' => now()->toIso8601String(),
            'source' => 'incoming',
            'path' => $data['path'] ?? '',
            'method' => $data['method'] ?? '',
            'statusCode' => $data['statusCode'] ?? 0,
            'errorType' => $this->getErrorType($data['statusCode'] ?? 0),
            'message' => $this->truncate($data['responseBody'] ?? '', $this->maxMessageLength),
            'requestBody' => $this->truncateBody($data['requestBody'] ?? ''),
            'responseBody' => $this->truncateBody($data['responseBody'] ?? ''),
            'requestHeaders' => $this->redactHeaders($data['requestHeaders'] ?? []),
            'responseHeaders' => $data['responseHeaders'] ?? [],
            'durationMs' => round($data['durationMs'] ?? 0, 2),
            'clientIp' => $data['clientIp'] ?? 'unknown',
            'userAgent' => $data['userAgent'] ?? '',
            'queryString' => $data['queryString'] ?? '',
        ];

        $this->push($event);
    }

    /**
     * Outgoing (dış servis) hata event'i kaydet.
     */
    public function captureOutgoing(array $data): void
    {
        $event = [
            'id' => (string) Str::uuid(),
            'timestamp' => now()->toIso8601String(),
            'source' => 'outgoing',
            'path' => $data['url'] ?? '',
            'method' => $data['method'] ?? '',
            'statusCode' => $data['statusCode'] ?? 0,
            'errorType' => $data['errorType'] ?? $this->getErrorType($data['statusCode'] ?? 0),
            'message' => $this->truncate($data['responseBody'] ?? '', $this->maxMessageLength),
            'requestBody' => $this->truncateBody($data['requestBody'] ?? ''),
            'responseBody' => $this->truncateBody($data['responseBody'] ?? ''),
            'requestHeaders' => $this->redactHeaders($data['requestHeaders'] ?? []),
            'responseHeaders' => $data['responseHeaders'] ?? [],
            'durationMs' => round($data['durationMs'] ?? 0, 2),
            'serverAddress' => $data['serverAddress'] ?? 'unknown',
            'urlScheme' => $data['urlScheme'] ?? 'https',
            'userAgent' => $data['userAgent'] ?? '',
            'queryString' => $data['queryString'] ?? '',
        ];

        $this->push($event);
    }

    /**
     * Tüm hata event'lerini getir (en yeniden eskiye).
     *
     * @return array<int, array>
     */
    public function getAll(): array
    {
        try {
            $connection = $this->getRedisConnection();
            $items = $connection->lrange($this->redisKey, 0, -1);

            if (empty($items)) {
                return [];
            }

            $events = [];
            foreach ($items as $item) {
                $decoded = json_decode($item, true);
                if ($decoded !== null) {
                    $events[] = $decoded;
                }
            }

            return $events;
        } catch (\Throwable $e) {
            $this->reportOnce($e);
            return [];
        }
    }

    /**
     * Buffer'ı temizle.
     */
    public function clear(): void
    {
        try {
            $connection = $this->getRedisConnection();
            $connection->del($this->redisKey);
        } catch (\Throwable $e) {
            $this->reportOnce($e);
        }
    }

    /**
     * Event'i Redis'e push et — circular buffer.
     */
    private function push(array $event): void
    {
        try {
            $connection = $this->getRedisConnection();
            $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // LPUSH: en yeni başa eklenir
            $connection->lpush($this->redisKey, [$json]);

            // LTRIM: buffer size'ı aşarsa en eski event'leri sil
            $connection->ltrim($this->redisKey, 0, $this->maxBufferSize - 1);

            // TTL — 7 gün sonra otomatik silinsin
            $ttl = config('server-orchestrator.apm.ttl', 604800);
            if ($ttl !== null) {
                $connection->expire($this->redisKey, $ttl);
            }
        } catch (\Throwable $e) {
            $this->reportOnce($e);
        }
    }

    /**
     * Redis connection al.
     */
    private function getRedisConnection()
    {
        $connectionName = config('server-orchestrator.redis_connection', 'default');
        return Redis::connection($connectionName)->client();
    }

    /**
     * Header'lardaki hassas bilgileri redact et.
     */
    private function redactHeaders(array $headers): array
    {
        $redacted = [];
        foreach ($headers as $key => $value) {
            $normalizedKey = strtolower($key);
            if (in_array($normalizedKey, self::SENSITIVE_HEADERS, true)) {
                $redacted[$key] = '[REDACTED]';
            } else {
                // Laravel header'lar array olarak gelebilir
                $redacted[$key] = is_array($value) ? implode(', ', $value) : (string) $value;
            }
        }
        return $redacted;
    }

    /**
     * Body'yi max byte'a göre truncate et.
     */
    private function truncateBody(string $body): string
    {
        if (strlen($body) <= $this->maxBodySize) {
            return $body;
        }
        return substr($body, 0, $this->maxBodySize) . '... [truncated]';
    }

    /**
     * String'i belirli uzunluğa truncate et.
     */
    private function truncate(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }
        return substr($text, 0, $max) . '...';
    }

    /**
     * Hata raporlama — flood önleme, sadece ilk hatayı logla.
     */
    private function reportOnce(\Throwable $e): void
    {
        if (!self::$errorReported) {
            report($e);
            self::$errorReported = true;
        }
    }
}
