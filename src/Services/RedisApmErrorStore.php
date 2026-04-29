<?php

namespace Fogeto\ServerOrchestrator\Services;

use Fogeto\ServerOrchestrator\Contracts\IApmErrorStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Str;

class RedisApmErrorStore implements IApmErrorStore
{
    private static bool $errorReported = false;

    private string $indexKey;

    private string $prefix;

    private string $service;

    private int $ttl;

    private int $maxLimit;

    private int $capacity;

    public function __construct(private Connection $redis)
    {
        $config = config('server-orchestrator.apm', []);
        $redisConfig = $config['redis'] ?? [];

        $this->ttl = max(1, (int) ($config['ttl'] ?? 86400));
        $this->maxLimit = max(1, (int) ($config['max_limit'] ?? 500));
        $this->capacity = max(1, (int) ($config['channel_capacity'] ?? $config['max_buffer_size'] ?? 1000));
        $this->service = (string) ($config['service'] ?? config('server-orchestrator.prefix', config('app.name', 'laravel')));

        $rawPrefix = trim((string) ($redisConfig['prefix'] ?? ''));
        if ($rawPrefix === '') {
            $rawPrefix = (string) config('server-orchestrator.prefix', 'laravel');
        }

        $sanitized = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $rawPrefix));
        $this->prefix = 'apm:' . $sanitized . ':';
        $this->indexKey = $this->prefix . 'events';
    }

    public function tryEnqueue(array $event): bool
    {
        try {
            $event['id'] = (string) ($event['id'] ?? Str::uuid());
            $event['service'] = (string) ($event['service'] ?? $this->service);
            $event['timestamp'] = (string) ($event['timestamp'] ?? now('UTC')->format('Y-m-d\TH:i:s.v\Z'));

            $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return false;
            }

            $this->pruneExpired();

            $eventKey = $this->eventKey($event['id']);
            $score = $this->scoreFromTimestamp($event['timestamp']);

            $this->redis->setex($eventKey, $this->ttl, $json);
            $this->redis->zadd($this->indexKey, $score, $event['id']);
            $this->redis->expire($this->indexKey, $this->ttl);
            $this->trimToCapacity();

            return true;
        } catch (\Throwable $e) {
            $this->reportOnce($e);

            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecent(int $limit): array
    {
        try {
            $this->pruneExpired();

            $limit = min(max(1, $limit), $this->maxLimit);
            $ids = $this->ensureList($this->redis->zrevrange($this->indexKey, 0, $limit - 1));

            if (empty($ids)) {
                return [];
            }

            $keys = array_map(fn (string $id): string => $this->eventKey($id), $ids);
            $payloads = $this->ensureList($this->redis->mget($keys));

            $events = [];
            foreach ($ids as $index => $id) {
                $payload = $payloads[$index] ?? null;
                if (! is_string($payload) || $payload === '') {
                    $this->redis->zrem($this->indexKey, $id);
                    continue;
                }

                $event = json_decode($payload, true);
                if (is_array($event)) {
                    $events[] = $event;
                }
            }

            return $events;
        } catch (\Throwable $e) {
            $this->reportOnce($e);

            return [];
        }
    }

    public function clear(): void
    {
        try {
            $ids = $this->ensureList($this->redis->zrange($this->indexKey, 0, -1));
            $keys = array_map(fn (string $id): string => $this->eventKey($id), $ids);
            $keys[] = $this->indexKey;

            $this->deleteKeys($keys);
        } catch (\Throwable $e) {
            $this->reportOnce($e);
        }
    }

    private function pruneExpired(): void
    {
        $minScore = '-inf';
        $maxScore = (string) ((int) floor((microtime(true) - $this->ttl) * 1000));

        $this->redis->zremrangebyscore($this->indexKey, $minScore, $maxScore);
    }

    private function trimToCapacity(): void
    {
        $this->redis->zremrangebyrank($this->indexKey, 0, -($this->capacity + 1));
    }

    private function eventKey(string $id): string
    {
        return $this->prefix . 'event:' . $id;
    }

    private function scoreFromTimestamp(string $timestamp): int
    {
        try {
            return (int) floor((new \DateTimeImmutable($timestamp))->format('U.u') * 1000);
        } catch (\Throwable) {
            return (int) floor(microtime(true) * 1000);
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function ensureList(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function deleteKeys(array $keys): void
    {
        $keys = array_values(array_filter($keys, static fn (string $key): bool => $key !== ''));

        foreach (array_chunk($keys, 100) as $chunk) {
            try {
                $this->redis->del($chunk);
            } catch (\Throwable) {
                foreach ($chunk as $key) {
                    $this->redis->del($key);
                }
            }
        }
    }

    private function reportOnce(\Throwable $e): void
    {
        if (self::$errorReported) {
            return;
        }

        report($e);
        self::$errorReported = true;
    }
}
