<?php

namespace Fogeto\ServerOrchestrator\Tests\Unit;

use Carbon\CarbonImmutable;
use Fogeto\ServerOrchestrator\Services\RedisApmErrorStore;
use Fogeto\ServerOrchestrator\Tests\Fakes\InMemoryRedisConnection;
use Fogeto\ServerOrchestrator\Tests\TestCase;

final class RedisApmErrorStoreTest extends TestCase
{
    public function test_it_round_trips_events_with_laravel_redis_connection_interface(): void
    {
        config([
            'server-orchestrator.prefix' => 'fallback',
            'server-orchestrator.apm.service' => 'ikbackend',
            'server-orchestrator.apm.ttl' => 86400,
            'server-orchestrator.apm.max_limit' => 500,
            'server-orchestrator.apm.redis.prefix' => 'IK Backend',
        ]);

        $connection = new InMemoryRedisConnection();
        $store = new RedisApmErrorStore($connection);

        $this->assertTrue($store->tryEnqueue([
            'id' => 'event-1',
            'timestamp' => '2026-04-30T10:00:00.000Z',
            'path' => '/api/test',
            'method' => 'GET',
            'statusCode' => 404,
        ]));

        $events = $store->getRecent(5);

        $this->assertCount(1, $events);
        $this->assertSame('event-1', $events[0]['id']);
        $this->assertSame('ikbackend', $events[0]['service']);
        $this->assertSame('/api/test', $events[0]['path']);

        $client = $connection->rawClient();
        $this->assertArrayHasKey('apm:ik_backend:events', $client->zsets);
        $this->assertSame(86400, $client->ttl['apm:ik_backend:event:event-1']);
        $this->assertSame(86400, $client->ttl['apm:ik_backend:events']);
    }

    public function test_it_prunes_expired_index_entries_and_trims_to_capacity(): void
    {
        CarbonImmutable::setTestNow('2026-04-30 12:00:00');

        config([
            'server-orchestrator.apm.service' => 'ikbackend',
            'server-orchestrator.apm.ttl' => 60,
            'server-orchestrator.apm.max_limit' => 500,
            'server-orchestrator.apm.channel_capacity' => 2,
            'server-orchestrator.apm.redis.prefix' => 'ikbackend',
        ]);

        $store = new RedisApmErrorStore(new InMemoryRedisConnection());

        $store->tryEnqueue([
            'id' => 'expired',
            'timestamp' => '2026-04-30T11:58:00.000Z',
            'path' => '/expired',
            'method' => 'GET',
            'statusCode' => 404,
        ]);
        $store->tryEnqueue([
            'id' => 'event-1',
            'timestamp' => '2026-04-30T11:59:30.000Z',
            'path' => '/first',
            'method' => 'GET',
            'statusCode' => 404,
        ]);
        $store->tryEnqueue([
            'id' => 'event-2',
            'timestamp' => '2026-04-30T12:00:00.000Z',
            'path' => '/second',
            'method' => 'GET',
            'statusCode' => 500,
        ]);

        $events = $store->getRecent(5);

        $this->assertSame(['event-2', 'event-1'], array_column($events, 'id'));

        CarbonImmutable::setTestNow();
    }

    public function test_clear_removes_index_and_event_payloads(): void
    {
        config([
            'server-orchestrator.apm.service' => 'ikbackend',
            'server-orchestrator.apm.redis.prefix' => 'ikbackend',
        ]);

        $connection = new InMemoryRedisConnection();
        $store = new RedisApmErrorStore($connection);

        $store->tryEnqueue([
            'id' => 'event-1',
            'timestamp' => '2026-04-30T10:00:00.000Z',
            'path' => '/api/test',
            'method' => 'GET',
            'statusCode' => 404,
        ]);

        $store->clear();

        $this->assertSame([], $store->getRecent(5));
        $this->assertSame([], $connection->rawClient()->strings);
        $this->assertSame([], $connection->rawClient()->zsets);
    }
}
