<?php

namespace Fogeto\ServerOrchestrator\Adapters;

use Prometheus\Storage\Adapter;

final class FaultTolerantAdapter implements Adapter
{
    private static bool $errorReported = false;

    public function __construct(private Adapter $adapter) {}

    public function collect(bool $sortMetrics = true): array
    {
        return $this->adapter->collect($sortMetrics);
    }

    public function updateSummary(array $data): void
    {
        $this->attemptWrite(fn () => $this->adapter->updateSummary($data));
    }

    public function updateHistogram(array $data): void
    {
        $this->attemptWrite(fn () => $this->adapter->updateHistogram($data));
    }

    public function updateGauge(array $data): void
    {
        $this->attemptWrite(fn () => $this->adapter->updateGauge($data));
    }

    public function updateCounter(array $data): void
    {
        $this->attemptWrite(fn () => $this->adapter->updateCounter($data));
    }

    public function wipeStorage(): void
    {
        $this->adapter->wipeStorage();
    }

    private function attemptWrite(callable $write): void
    {
        try {
            $write();
        } catch (\Throwable $e) {
            $this->reportOnce($e);
        }
    }

    private function reportOnce(\Throwable $e): void
    {
        if (self::$errorReported) {
            return;
        }

        // Reporting can trigger SQL listeners. Set the guard first so a broken
        // metrics backend cannot cause recursive reporting or mask the request.
        self::$errorReported = true;

        try {
            report($e);
        } catch (\Throwable) {
            // Observability failures must never affect the business request.
        }
    }
}
