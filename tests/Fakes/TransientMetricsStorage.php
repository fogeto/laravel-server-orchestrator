<?php

namespace Fogeto\ServerOrchestrator\Tests\Fakes;

use Prometheus\Storage\InMemory;
use RuntimeException;

final class TransientMetricsStorage extends InMemory
{
    public bool $unavailable = true;
    public int $attempts = 0;

    public function collect(bool $sortMetrics = true): array
    {
        $this->checkAvailability();
        return parent::collect($sortMetrics);
    }

    public function updateGauge(array $data): void
    {
        $this->checkAvailability();
        parent::updateGauge($data);
    }

    public function updateCounter(array $data): void
    {
        $this->checkAvailability();
        parent::updateCounter($data);
    }

    public function updateHistogram(array $data): void
    {
        $this->checkAvailability();
        parent::updateHistogram($data);
    }

    private function checkAvailability(): void
    {
        $this->attempts++;
        if ($this->unavailable) {
            throw new RuntimeException('synthetic-storage-failure');
        }
    }
}
