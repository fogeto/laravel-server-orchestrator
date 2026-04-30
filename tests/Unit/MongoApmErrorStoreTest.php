<?php

namespace Fogeto\ServerOrchestrator\Tests\Unit;

use Fogeto\ServerOrchestrator\Services\MongoApmErrorStore;
use Fogeto\ServerOrchestrator\Tests\TestCase;

final class MongoApmErrorStoreTest extends TestCase
{
    public function test_store_is_disabled_without_required_mongo_config(): void
    {
        config([
            'server-orchestrator.apm.mongo.connection_string' => '',
            'server-orchestrator.apm.mongo.database' => '',
            'server-orchestrator.apm.mongo.collection' => 'ApmErrors',
        ]);

        $store = new MongoApmErrorStore();

        $this->assertFalse($store->tryEnqueue([
            'path' => '/missing-config',
            'method' => 'GET',
            'statusCode' => 404,
        ]));
        $this->assertSame([], $store->getRecent(5));

        $store->clear();
        $this->assertTrue(true);
    }
}
