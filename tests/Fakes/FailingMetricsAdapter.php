<?php

namespace Fogeto\ServerOrchestrator\Tests\Fakes;

use Prometheus\Storage\Adapter;
use RuntimeException;

final class FailingMetricsAdapter implements Adapter
{
    public int $counterWrites = 0;

    public int $gaugeWrites = 0;

    public int $histogramWrites = 0;

    public int $summaryWrites = 0;

    public function collect(bool $sortMetrics = true): array
    {
        return [];
    }

    public function updateSummary(array $data): void
    {
        $this->summaryWrites++;
        $this->fail();
    }

    public function updateHistogram(array $data): void
    {
        $this->histogramWrites++;
        $this->fail();
    }

    public function updateGauge(array $data): void
    {
        $this->gaugeWrites++;
        $this->fail();
    }

    public function updateCounter(array $data): void
    {
        $this->counterWrites++;
        $this->fail();
    }

    public function wipeStorage(): void {}

    private function fail(): void
    {
        throw new RuntimeException('Metrics storage is unavailable.');
    }
}
