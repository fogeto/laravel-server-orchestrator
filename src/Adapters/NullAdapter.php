<?php

namespace Fogeto\ServerOrchestrator\Adapters;

use Fogeto\ServerOrchestrator\Exceptions\MetricsStorageUnavailable;
use Prometheus\Storage\Adapter;

final class NullAdapter implements Adapter
{
    public function collect(bool $sortMetrics = true): array
    {
        throw new MetricsStorageUnavailable('Metrics storage could not be initialized.');
    }

    public function updateSummary(array $data): void {}

    public function updateHistogram(array $data): void {}

    public function updateGauge(array $data): void {}

    public function updateCounter(array $data): void {}

    public function wipeStorage(): void {}
}
