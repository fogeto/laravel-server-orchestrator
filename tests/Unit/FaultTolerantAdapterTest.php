<?php

namespace Fogeto\ServerOrchestrator\Tests\Unit;

use Fogeto\ServerOrchestrator\Adapters\FaultTolerantAdapter;
use Fogeto\ServerOrchestrator\Tests\Fakes\FailingMetricsAdapter;
use Fogeto\ServerOrchestrator\Tests\TestCase;
use Prometheus\CollectorRegistry;

final class FaultTolerantAdapterTest extends TestCase
{
    public function test_registry_creation_survives_the_default_metric_write_failure(): void
    {
        $storage = new FailingMetricsAdapter();

        $registry = new CollectorRegistry(new FaultTolerantAdapter($storage));

        $this->assertInstanceOf(CollectorRegistry::class, $registry);
        $this->assertSame(1, $storage->gaugeWrites);
    }

    public function test_it_skips_all_metric_writes_when_storage_is_unavailable(): void
    {
        $storage = new FailingMetricsAdapter();
        $adapter = new FaultTolerantAdapter($storage);

        $adapter->updateGauge([]);
        $adapter->updateCounter([]);
        $adapter->updateHistogram([]);
        $adapter->updateSummary([]);

        $this->assertSame(1, $storage->gaugeWrites);
        $this->assertSame(1, $storage->counterWrites);
        $this->assertSame(1, $storage->histogramWrites);
        $this->assertSame(1, $storage->summaryWrites);
    }
}
