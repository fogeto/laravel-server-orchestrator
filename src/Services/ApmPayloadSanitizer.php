<?php

namespace Fogeto\ServerOrchestrator\Services;

final class ApmPayloadSanitizer
{
    private const REDACTED = '***REDACTED***';
    private const OMITTED = '[unstructured body omitted]';
    private array $sensitiveFields;

    public function __construct(array $sensitiveFields = [])
    {
        $this->sensitiveFields = array_map(fn ($field) => $this->normalize((string) $field), $sensitiveFields);
    }

    public function event(array $event): array
    {
        foreach (['requestBody', 'responseBody'] as $key) {
            $event[$key] = $this->body((string) ($event[$key] ?? ''));
        }
        $event['message'] = $event['responseBody'] !== ''
            ? $event['responseBody'] : $this->body((string) ($event['message'] ?? ''));
        foreach (['requestHeaders', 'responseHeaders'] as $key) {
            $event[$key] = $this->headers(is_array($event[$key] ?? null) ? $event[$key] : []);
        }
        $event['queryString'] = $this->query((string) ($event['queryString'] ?? ''));
        $event['path'] = $this->url((string) ($event['path'] ?? ''));

        return $this->redactValues($event);
    }

    public function body(string $body): string
    {
        if ($body === '') {
            return '';
        }
        if (preg_match('//u', $body) !== 1) {
            return '[non-utf8 body omitted]';
        }
        $data = json_decode($body, true, 32, JSON_BIGINT_AS_STRING);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return json_encode($this->redactValues($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: self::OMITTED;
        }
        // A truncated/malformed JSON payload must not fall through to raw text.
        if (preg_match('/^[^{}\[\]<>\r\n=]+=[^<>\r\n]*$/s', $body) === 1) {
            return $this->query($body);
        }
        return self::OMITTED;
    }

    public function headers(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $value) {
            $normalized = $this->normalize((string) $name);
            $text = is_array($value) ? implode(', ', $value) : (string) $value;
            if ($this->isSensitive((string) $name)) {
                $result[$name] = self::REDACTED;
            } elseif (in_array($normalized, ['link', 'refresh'], true)) {
                // Compound URL headers include arbitrary attributes; omit rather
                // than risk retaining credentials in an incompletely parsed part.
                $result[$name] = '[URL-bearing header omitted]';
            } elseif (in_array($normalized, ['location', 'contentlocation', 'referer', 'referrer'], true)) {
                $result[$name] = $this->url($text);
            } else {
                $result[$name] = $this->safeText($text);
            }
        }
        return $result;
    }

    public function query(string $query, int $depth = 0): string
    {
        if ($query === '') {
            return '';
        }
        if (preg_match('//u', $query) !== 1 || strlen($query) > 32768) {
            return '[query omitted]';
        }
        parse_str($query, $values);
        return http_build_query($this->redactValues($values, $depth + 1), '', '&', PHP_QUERY_RFC3986);
    }

    public function url(string $url, int $depth = 0): string
    {
        if ($url === '') {
            return '';
        }
        if ($depth >= 32) {
            return '[nested URL omitted]';
        }
        $parts = parse_url($url);
        if ($parts === false || preg_match('//u', $url) !== 1 || preg_match('/[<>\s]/', $url)) {
            return '[invalid URL omitted]';
        }
        if (isset($parts['scheme']) && ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return '[unsupported URL omitted]';
        }
        $result = isset($parts['scheme']) ? $parts['scheme'] . '://' : (isset($parts['host']) ? '//' : '');
        if (isset($parts['host'])) {
            $result .= $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }
        $result .= $parts['path'] ?? '';
        if (isset($parts['query'])) {
            $result .= '?' . $this->query($parts['query'], $depth + 1);
        }
        // Userinfo and fragments can carry credentials; neither is needed for APM.
        return $result;
    }

    private function redactValues(array $values, int $depth = 0): array
    {
        if ($depth >= 32) {
            return ['omitted' => '[maximum depth exceeded]'];
        }
        foreach ($values as $key => $value) {
            if ($this->isSensitive((string) $key)) {
                $values[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $values[$key] = $this->redactValues($value, $depth + 1);
            } elseif (is_string($value)) {
                $values[$key] = $this->safeText($value, $depth + 1);
            }
        }
        return $values;
    }

    private function isSensitive(string $name): bool
    {
        $normalized = $this->normalize($name);
        return in_array($normalized, $this->sensitiveFields, true)
            || $normalized === 'pwd'
            || preg_match('/password|passwd|secret|token|credential|authorization|cookie|apikey|privatekey|sessionid|xsrf|csrf|signature/', $normalized) === 1;
    }

    private function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $name));
    }

    private function safeText(string $value, int $depth = 0): string
    {
        $relativeQueryUrl = preg_match('/^[^\s{}\[\]"<>]+\?[^\s{}\[\]"<>]*$/u', $value) === 1;
        if (preg_match('#^(?:https?:)?//#i', $value) === 1 || str_starts_with($value, '/') || $relativeQueryUrl) {
            return $this->url($value, $depth + 1);
        }
        return preg_match('//u', $value) === 1 ? $value : '[non-utf8 text omitted]';
    }
}
