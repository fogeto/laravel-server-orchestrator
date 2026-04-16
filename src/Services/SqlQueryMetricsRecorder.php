<?php

namespace Fogeto\ServerOrchestrator\Services;

use Fogeto\ServerOrchestrator\Helpers\SqlParser;
use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\Histogram;

class SqlQueryMetricsRecorder
{
    private ?Histogram $durationHistogram = null;

    private ?Counter $errorCounter = null;

    private bool $errorReported = false;

    /**
     * @var array<string, bool>
     */
    private array $knownHashes = [];

    private bool $includeQueryLabel;

    private int $maxQueryLength;

    private int $maxUniqueQueries;

    /**
     * @var array<int, string>
     */
    private array $ignorePatterns;

    /**
     * @var array<int, float>
     */
    private array $buckets;

    public function __construct(private CollectorRegistry $registry)
    {
        $config = config('server-orchestrator.sql_metrics', []);

        $this->includeQueryLabel = (bool) ($config['include_query_label'] ?? true);
        $this->maxQueryLength = (int) ($config['query_max_length'] ?? 200);
        $this->maxUniqueQueries = max(1, (int) ($config['max_unique_queries'] ?? 100));
        $this->ignorePatterns = $config['ignore_patterns'] ?? [];
        $this->buckets = $config['histogram_buckets'] ?? [
            0.005, 0.01, 0.025, 0.05, 0.1, 0.25,
            0.5, 1.0, 2.5, 5.0, 10.0,
        ];
    }

    public function recordDuration(string $sql, float $duration): void
    {
        try {
            $parsed = $this->parse($sql);

            if ($parsed === null) {
                return;
            }

            $this->ensureDurationHistogram();

            if ($this->durationHistogram === null) {
                return;
            }

            $labels = [
                $parsed['query_hash'],
                $parsed['operation'],
                $parsed['table'],
            ];

            if ($this->includeQueryLabel) {
                $labels[] = SqlParser::sanitizeForLabel($parsed['query'], $this->maxQueryLength);
            }

            $this->durationHistogram->observe($duration, $labels);
        } catch (\Throwable $e) {
            $this->reportOnce($e);
        }
    }

    public function recordError(string $sql): void
    {
        try {
            $parsed = $this->parse($sql);

            if ($parsed === null) {
                return;
            }

            $this->ensureErrorCounter();

            if ($this->errorCounter === null) {
                return;
            }

            $this->errorCounter->inc([
                $parsed['query_hash'],
                $parsed['operation'],
                $parsed['table'],
            ]);
        } catch (\Throwable $e) {
            $this->reportOnce($e);
        }
    }

    /**
     * @return array{operation: string, table: string, query_hash: string, query: string}|null
     */
    private function parse(string $sql): ?array
    {
        if ($this->shouldIgnore($sql)) {
            return null;
        }

        $parsed = SqlParser::parse($sql);

        if (! $this->rememberQueryHash($parsed['query_hash'])) {
            return null;
        }

        return $parsed;
    }

    private function shouldIgnore(string $sql): bool
    {
        foreach ($this->ignorePatterns as $pattern) {
            if (@preg_match($pattern, $sql) === 1) {
                return true;
            }
        }

        return false;
    }

    private function rememberQueryHash(string $queryHash): bool
    {
        if (isset($this->knownHashes[$queryHash])) {
            return true;
        }

        if (count($this->knownHashes) >= $this->maxUniqueQueries) {
            return false;
        }

        $this->knownHashes[$queryHash] = true;

        return true;
    }

    private function ensureDurationHistogram(): void
    {
        if ($this->durationHistogram !== null) {
            return;
        }

        $labelNames = ['query_hash', 'operation', 'table'];

        if ($this->includeQueryLabel) {
            $labelNames[] = 'query';
        }

        $this->durationHistogram = $this->registry->getOrRegisterHistogram(
            'sql',
            'query_duration_seconds',
            'SQL query execution duration',
            $labelNames,
            $this->buckets
        );
    }

    private function ensureErrorCounter(): void
    {
        if ($this->errorCounter !== null) {
            return;
        }

        $this->errorCounter = $this->registry->getOrRegisterCounter(
            'sql',
            'query_errors_total',
            'SQL query error count',
            ['query_hash', 'operation', 'table']
        );
    }

    private function reportOnce(\Throwable $e): void
    {
        if ($this->errorReported) {
            return;
        }

        report($e);
        $this->errorReported = true;
    }
}
