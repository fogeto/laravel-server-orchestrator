<?php

namespace Fogeto\ServerOrchestrator\Adapters;

use Prometheus\Storage\Adapter;

final class NullAdapter implements Adapter
{
    public function collect(bool $sortMetrics = true): array
    {
        return [];
    }

    public function updateSummary(array $data): void {}

    public function updateHistogram(array $data): void {}

    public function updateGauge(array $data): void {}

    public function updateCounter(array $data): void {}

    public function wipeStorage(): void {}
}
