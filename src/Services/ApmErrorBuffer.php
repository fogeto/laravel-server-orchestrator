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

    private string $service;

    public function __construct(private IApmErrorStore $store)
    {
        $this->maxBodySize = (int) config('server-orchestrator.apm.max_body_size', 32768);
        $this->maxMessageLength = (int) config('server-orchestrator.apm.max_message_length', 200);
        $this->defaultLimit = (int) config('server-orchestrator.apm.default_limit', 200);
        $this->service = (string) config(
            'server-orchestrator.apm.service',
            config('server-orchestrator.prefix', config('app.name', 'laravel'))
        );
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
            'service' => $this->service,
            'timestamp' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'path' => $this->sanitizeText((string) ($data['path'] ?? '')),
            'method' => $this->sanitizeText((string) ($data['method'] ?? '')),
            'statusCode' => $data['statusCode'] ?? 0,
            'errorType' => $this->getErrorType($data['statusCode'] ?? 0),
            'message' => $this->truncate($data['responseBody'] ?? '', $this->maxMessageLength),
            'requestBody' => $this->truncateBody($data['requestBody'] ?? ''),
            'responseBody' => $this->truncateBody($data['responseBody'] ?? ''),
            'requestHeaders' => $this->redactHeaders($data['requestHeaders'] ?? []),
            'responseHeaders' => $this->normalizeHeaders($data['responseHeaders'] ?? []),
            'durationMs' => round((float) ($data['durationMs'] ?? 0), 2),
            'clientIp' => $this->sanitizeText((string) ($data['clientIp'] ?? 'unknown')),
            'userAgent' => $this->sanitizeText((string) ($data['userAgent'] ?? '')),
            'queryString' => $this->sanitizeText((string) ($data['queryString'] ?? '')),
        ]);
    }

    public function captureOutgoing(array $data): void
    {
        $this->store->tryEnqueue([
            'id' => (string) Str::uuid(),
            'service' => $this->service,
            'timestamp' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'source' => 'outgoing',
            'path' => $this->sanitizeText((string) ($data['url'] ?? '')),
            'method' => $this->sanitizeText((string) ($data['method'] ?? '')),
            'statusCode' => $data['statusCode'] ?? 0,
            'errorType' => $data['errorType'] ?? $this->getErrorType($data['statusCode'] ?? 0),
            'message' => $this->truncate($data['responseBody'] ?? '', $this->maxMessageLength),
            'requestBody' => $this->truncateBody($data['requestBody'] ?? ''),
            'responseBody' => $this->truncateBody($data['responseBody'] ?? ''),
            'requestHeaders' => $this->redactHeaders($data['requestHeaders'] ?? []),
            'responseHeaders' => $this->normalizeHeaders($data['responseHeaders'] ?? []),
            'durationMs' => round((float) ($data['durationMs'] ?? 0), 2),
            'userAgent' => $this->sanitizeText((string) ($data['userAgent'] ?? '')),
            'queryString' => $this->sanitizeText((string) ($data['queryString'] ?? '')),
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
                : $this->sanitizeText(is_array($value) ? implode(', ', $value) : (string) $value);
        }

        return $redacted;
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[(string) $key] = $this->sanitizeText(is_array($value) ? implode(', ', $value) : (string) $value);
        }

        return $normalized;
    }

    private function truncateBody(string $body): string
    {
        $body = $this->sanitizeText($body);

        if (strlen($body) <= $this->maxBodySize) {
            return $body;
        }

        return $this->truncateUtf8($body, $this->maxBodySize);
    }

    private function truncate(string $text, int $max): string
    {
        $text = $this->sanitizeText($text);

        if (strlen($text) <= $max) {
            return $text;
        }

        return $this->truncateUtf8($text, $max);
    }

    private function sanitizeText(string $text): string
    {
        if ($this->isValidUtf8($text)) {
            return $text;
        }

        return sprintf(
            '[non-utf8 string omitted; bytes=%d; base64_prefix=%s]',
            strlen($text),
            substr(base64_encode(substr($text, 0, 48)), 0, 96)
        );
    }

    private function truncateUtf8(string $text, int $maxBytes): string
    {
        if (function_exists('mb_strcut')) {
            return mb_strcut($text, 0, $maxBytes, 'UTF-8');
        }

        $truncated = substr($text, 0, $maxBytes);
        while ($truncated !== '' && ! $this->isValidUtf8($truncated)) {
            $truncated = substr($truncated, 0, -1);
        }

        return $truncated;
    }

    private function isValidUtf8(string $text): bool
    {
        return $text === '' || preg_match('//u', $text) === 1;
    }
}
