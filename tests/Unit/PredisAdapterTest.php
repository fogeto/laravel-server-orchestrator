<?php

namespace Fogeto\ServerOrchestrator\Tests\Unit;

use Fogeto\ServerOrchestrator\Adapters\FaultTolerantAdapter;
use Fogeto\ServerOrchestrator\Adapters\PredisAdapter;
use Fogeto\ServerOrchestrator\Tests\Fakes\FlakyInMemoryRedisConnection;
use Fogeto\ServerOrchestrator\Tests\TestCase;

final class PredisAdapterTest extends TestCase
{
    public function test_failed_write_retries_metric_metadata_after_redis_recovers(): void
    {
        $connection = new FlakyInMemoryRedisConnection();
        $adapter = new FaultTolerantAdapter(
            new PredisAdapter($connection, 'prometheus:retry_test:', null)
        );
        $metric = [
            'name' => 'recovered_counter',
            'help' => 'Counter used to verify recovery.',
            'labelNames' => ['result'],
            'labelValues' => ['ok'],
            'value' => 1,
        ];

        $adapter->updateCounter($metric);
        $adapter->updateCounter($metric);

        $client = $connection->rawClient();
        $this->assertArrayHasKey(
            'recovered_counter',
            $client->hashes['prometheus:retry_test:counters:meta'] ?? []
        );
        $this->assertSame(
            1.0,
            array_sum($client->hashes['prometheus:retry_test:counters:recovered_counter'] ?? [])
        );
    }

    public function test_runtime_failure_invalidates_cached_metadata_for_the_next_write(): void
    {
        $connection = new FlakyInMemoryRedisConnection(null, false);
        $adapter = new FaultTolerantAdapter(
            new PredisAdapter($connection, 'prometheus:restart_test:', null)
        );
        $metric = [
            'name' => 'restart_counter',
            'help' => 'Counter used to verify Redis restart recovery.',
            'labelNames' => ['result'],
            'labelValues' => ['ok'],
            'value' => 1,
        ];

        $adapter->updateCounter($metric);

        $client = $connection->rawClient();
        $client->hashes = [];
        $connection->failNextPipeline();

        $adapter->updateCounter($metric);
        $adapter->updateCounter($metric);

        $this->assertArrayHasKey(
            'restart_counter',
            $client->hashes['prometheus:restart_test:counters:meta'] ?? []
        );
        $this->assertSame(
            1.0,
            array_sum($client->hashes['prometheus:restart_test:counters:restart_counter'] ?? [])
        );
    }
}
