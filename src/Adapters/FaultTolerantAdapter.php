<?php

namespace Fogeto\ServerOrchestrator\Adapters;

use Closure;
use Fogeto\ServerOrchestrator\Exceptions\MetricsStorageUnavailable;
use Fogeto\ServerOrchestrator\Services\MonitoringErrorReporter;
use Prometheus\Storage\Adapter;

final class FaultTolerantAdapter implements Adapter
{
    private float $retryAfter = 0.0;
    private Closure $clock;
    private MonitoringErrorReporter $reporter;

    public function __construct(
        private Adapter|Closure $adapter,
        private float $cooldownSeconds = 5.0,
        ?MonitoringErrorReporter $reporter = null,
        ?Closure $clock = null
    ) {
        $this->cooldownSeconds = is_finite($cooldownSeconds) ? max(0.0, $cooldownSeconds) : 5.0;
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->reporter = $reporter ?? app(MonitoringErrorReporter::class);
    }

    public function collect(bool $sortMetrics = true): array
    {
        if ($this->isUnavailable()) {
            throw new MetricsStorageUnavailable('Metrics storage is temporarily unavailable.');
        }
        try {
            return $this->storage()->collect($sortMetrics);
        } catch (\Throwable $e) {
            $this->markUnavailable($e);
            throw new MetricsStorageUnavailable('Metrics storage is temporarily unavailable.', 0, $e);
        }
    }

    public function updateSummary(array $data): void
    {
        $this->attemptWrite(fn () => $this->storage()->updateSummary($data));
    }

    public function updateHistogram(array $data): void
    {
        $this->attemptWrite(fn () => $this->storage()->updateHistogram($data));
    }

    public function updateGauge(array $data): void
    {
        $this->attemptWrite(fn () => $this->storage()->updateGauge($data));
    }

    public function updateCounter(array $data): void
    {
        $this->attemptWrite(fn () => $this->storage()->updateCounter($data));
    }

    public function wipeStorage(): void
    {
        $this->storage()->wipeStorage();
    }

    private function storage(): Adapter
    {
        if ($this->adapter instanceof Closure) {
            $this->adapter = ($this->adapter)();
        }
        return $this->adapter;
    }

    private function attemptWrite(callable $write): void
    {
        if ($this->isUnavailable()) {
            return;
        }
        try {
            $write();
            $this->retryAfter = 0.0;
        } catch (\Throwable $e) {
            $this->markUnavailable($e);
        }
    }

    private function isUnavailable(): bool
    {
        return ($this->clock)() < $this->retryAfter;
    }

    private function markUnavailable(\Throwable $e): void
    {
        $this->retryAfter = ($this->clock)() + $this->cooldownSeconds;
        $this->reporter->report($e);
    }
}
