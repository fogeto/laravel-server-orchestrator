<?php

namespace Fogeto\ServerOrchestrator\Services;

use Fogeto\ServerOrchestrator\Contracts\IApmErrorStore;
use Illuminate\Support\Str;

class ApmErrorBuffer
{
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

    private const CAPTURED_STATUS_CODES = [400, 401, 403, 404, 429, 500, 502, 503];

    private const SENSITIVE_HEADERS = [
        'authorization',
        'cookie',
        'x-api-key',
        'x-csrf-token',
        'set-cookie',
    ];

    private int $maxBodySize;

    private int $maxMessageLength;

    private int $defaultLimit;

    public function __construct(private IApmErrorStore $store)
    {
        $this->maxBodySize = (int) config('server-orchestrator.apm.max_body_size', 32768);
        $this->maxMessageLength = (int) config('server-orchestrator.apm.max_message_length', 200);
        $this->defaultLimit = (int) config('server-orchestrator.apm.default_limit', 200);
    }

    public function shouldCapture(int $statusCode): bool
    {
        return in_array($statusCode, self::CAPTURED_STATUS_CODES, true);
    }

    public function getErrorType(int $statusCode): string
    {
        return self::ERROR_TYPES[$statusCode] ?? 'HTTP ' . $statusCode;
    }

    public function captureIncoming(array $data): void
    {
        $this->store->tryEnqueue([
            'id' => (string) Str::uuid(),
            'timestamp' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'path' => $data['path'] ?? '',
            'method' => $data['method'] ?? '',
            'statusCode' => $data['statusCode'] ?? 0,
            'errorType' => $this->getErrorType($data['statusCode'] ?? 0),
            'message' => $this->truncate($data['responseBody'] ?? '', $this->maxMessageLength),
            'requestBody' => $this->truncateBody($data['requestBody'] ?? ''),
            'responseBody' => $this->truncateBody($data['responseBody'] ?? ''),
            'requestHeaders' => $this->redactHeaders($data['requestHeaders'] ?? []),
            'responseHeaders' => $this->normalizeHeaders($data['responseHeaders'] ?? []),
            'durationMs' => round((float) ($data['durationMs'] ?? 0), 2),
            'clientIp' => $data['clientIp'] ?? 'unknown',
            'userAgent' => $data['userAgent'] ?? '',
            'queryString' => $data['queryString'] ?? '',
        ]);
    }

    public function captureOutgoing(array $data): void
    {
        $this->store->tryEnqueue([
            'id' => (string) Str::uuid(),
            'timestamp' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'source' => 'outgoing',
            'path' => $data['url'] ?? '',
            'method' => $data['method'] ?? '',
            'statusCode' => $data['statusCode'] ?? 0,
            'errorType' => $data['errorType'] ?? $this->getErrorType($data['statusCode'] ?? 0),
            'message' => $this->truncate($data['responseBody'] ?? '', $this->maxMessageLength),
            'requestBody' => $this->truncateBody($data['requestBody'] ?? ''),
            'responseBody' => $this->truncateBody($data['responseBody'] ?? ''),
            'requestHeaders' => $this->redactHeaders($data['requestHeaders'] ?? []),
            'responseHeaders' => $this->normalizeHeaders($data['responseHeaders'] ?? []),
            'durationMs' => round((float) ($data['durationMs'] ?? 0), 2),
            'userAgent' => $data['userAgent'] ?? '',
            'queryString' => $data['queryString'] ?? '',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAll(?int $limit = null): array
    {
        return $this->store->getRecent($limit ?? $this->defaultLimit);
    }

    public function clear(): void
    {
        $this->store->clear();
    }

    private function redactHeaders(array $headers): array
    {
        $redacted = [];
        foreach ($headers as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            $redacted[(string) $key] = in_array($normalizedKey, self::SENSITIVE_HEADERS, true)
                ? '***REDACTED***'
                : (is_array($value) ? implode(', ', $value) : (string) $value);
        }

        return $redacted;
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[(string) $key] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return $normalized;
    }

    private function truncateBody(string $body): string
    {
        if (strlen($body) <= $this->maxBodySize) {
            return $body;
        }

        return substr($body, 0, $this->maxBodySize);
    }

    private function truncate(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max);
    }
}
