<?php

namespace Fogeto\ServerOrchestrator\Tests\Integration;

use Fogeto\ServerOrchestrator\Services\MongoApmErrorStore;
use Fogeto\ServerOrchestrator\Services\RedisApmErrorStore;
use Fogeto\ServerOrchestrator\Tests\TestCase;
use Illuminate\Support\Facades\Redis;

final class ApmStoreIntegrationTest extends TestCase
{
    public function test_mongo_store_round_trips_against_real_mongo_when_enabled(): void
    {
        $this->requireIntegrationTests();

        $uri = (string) getenv('APM_TEST_MONGO_URI');
        if ($uri === '') {
            $this->markTestSkipped('APM_TEST_MONGO_URI is not set.');
        }

        if (! class_exists('MongoDB\\Driver\\Manager')) {
            $this->markTestSkipped('ext-mongodb is not installed.');
        }

        $database = (string) (getenv('APM_TEST_MONGO_DATABASE') ?: 'orchestrator_apm_test');
        $service = 'phpunit-mongo-' . bin2hex(random_bytes(4));

        config([
            'server-orchestrator.apm.store' => 'mongo',
            'server-orchestrator.apm.service' => $service,
            'server-orchestrator.apm.scope_by_service' => true,
            'server-orchestrator.apm.ttl' => 3600,
            'server-orchestrator.apm.mongo.connection_string' => $uri,
            'server-orchestrator.apm.mongo.database' => $database,
            'server-orchestrator.apm.mongo.collection' => 'ApmErrors',
        ]);

        $store = new MongoApmErrorStore();
        $store->clear();

        $eventId = 'mongo-' . bin2hex(random_bytes(6));
        $this->assertTrue($store->tryEnqueue([
            'id' => $eventId,
            'service' => $service,
            'timestamp' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'path' => '/integration/mongo',
            'method' => 'GET',
            'statusCode' => 500,
        ]));

        $events = $store->getRecent(5);
        $store->clear();

        $this->assertContains($eventId, array_column($events, 'id'));
    }

    public function test_redis_store_round_trips_against_real_redis_with_predis_when_enabled(): void
    {
        $this->runRealRedisRoundTrip('predis');
    }

    public function test_redis_store_round_trips_against_real_redis_with_phpredis_when_enabled(): void
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed.');
        }

        $this->runRealRedisRoundTrip('phpredis');
    }

    private function runRealRedisRoundTrip(string $client): void
    {
        $this->requireIntegrationTests();

        config([
            'database.redis.client' => $client,
            'database.redis.apm_test' => [
                'url' => null,
                'host' => (string) (getenv('APM_TEST_REDIS_HOST') ?: '127.0.0.1'),
                'username' => null,
                'password' => $this->emptyStringToNull(getenv('APM_TEST_REDIS_PASSWORD')),
                'port' => (int) (getenv('APM_TEST_REDIS_PORT') ?: 6379),
                'database' => (int) (getenv('APM_TEST_REDIS_DATABASE') ?: 15),
            ],
            'server-orchestrator.apm.store' => 'redis',
            'server-orchestrator.apm.service' => 'phpunit-' . $client,
            'server-orchestrator.apm.ttl' => 3600,
            'server-orchestrator.apm.max_limit' => 500,
            'server-orchestrator.apm.redis.connection' => 'apm_test',
            'server-orchestrator.apm.redis.prefix' => 'phpunit-' . $client,
        ]);

        app('redis')->purge('apm_test');
        $connection = Redis::connection('apm_test');

        $store = new RedisApmErrorStore($connection);
        $store->clear();

        $eventId = $client . '-' . bin2hex(random_bytes(6));
        $this->assertTrue($store->tryEnqueue([
            'id' => $eventId,
            'timestamp' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'path' => '/integration/redis/' . $client,
            'method' => 'GET',
            'statusCode' => 404,
        ]));

        $events = $store->getRecent(5);
        $store->clear();

        $this->assertContains($eventId, array_column($events, 'id'));
    }

    private function requireIntegrationTests(): void
    {
        if ((string) getenv('APM_STORE_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set APM_STORE_INTEGRATION=1 to run real Mongo/Redis integration tests.');
        }
    }

    private function emptyStringToNull(mixed $value): ?string
    {
        $value = (string) $value;

        return $value === '' ? null : $value;
    }
}
