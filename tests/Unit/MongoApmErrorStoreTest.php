<?php

namespace Fogeto\ServerOrchestrator\Tests\Unit;

use Fogeto\ServerOrchestrator\Services\MongoApmErrorStore;
use Fogeto\ServerOrchestrator\Tests\TestCase;

final class MongoApmErrorStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetMongoStoreStatics();
    }

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

    public function test_constructor_drops_incompatible_existing_ttl_index_before_creating_indexes(): void
    {
        if (! class_exists('MongoDB\\Driver\\Manager')) {
            $this->markTestSkipped('ext-mongodb is not installed.');
        }

        config([
            'server-orchestrator.apm.ttl' => 86400,
            'server-orchestrator.apm.mongo.connection_string' => 'mongodb://localhost:27017',
            'server-orchestrator.apm.mongo.database' => 'orchestrator_test',
            'server-orchestrator.apm.mongo.collection' => 'ApmErrors',
        ]);

        $manager = new FakeMongoManager([
            [
                'name' => 'ix_apm_ttl',
                'key' => (object) ['createdAt' => 1],
                'expireAfterSeconds' => 3600,
            ],
        ]);

        new TestableMongoApmErrorStore($manager);

        $this->assertSame(['listIndexes', 'dropIndexes', 'createIndexes'], $manager->commandNames());
        $this->assertSame('ix_apm_ttl', $manager->commands[1]['index']);
    }

    public function test_constructor_updates_compatible_ttl_index_seconds_without_dropping_it(): void
    {
        if (! class_exists('MongoDB\\Driver\\Manager')) {
            $this->markTestSkipped('ext-mongodb is not installed.');
        }

        config([
            'server-orchestrator.apm.ttl' => 86400,
            'server-orchestrator.apm.mongo.connection_string' => 'mongodb://localhost:27017',
            'server-orchestrator.apm.mongo.database' => 'orchestrator_test',
            'server-orchestrator.apm.mongo.collection' => 'ApmErrors',
        ]);

        $manager = new FakeMongoManager([
            [
                'name' => 'ix_apm_ttl',
                'key' => (object) ['timestamp' => 1],
                'expireAfterSeconds' => 3600,
            ],
        ]);

        new TestableMongoApmErrorStore($manager);

        $this->assertSame(['listIndexes', 'collMod', 'createIndexes'], $manager->commandNames());
        $this->assertSame([
            'name' => 'ix_apm_ttl',
            'expireAfterSeconds' => 86400,
        ], $manager->commands[1]['index']);
    }

    private function resetMongoStoreStatics(): void
    {
        $reflection = new \ReflectionClass(MongoApmErrorStore::class);

        foreach (['indexesEnsured', 'errorReported'] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue(null, false);
        }
    }
}

final class TestableMongoApmErrorStore extends MongoApmErrorStore
{
    public function __construct(private FakeMongoManager $fakeManager)
    {
        parent::__construct();
    }

    protected function newManager(string $connectionString): object
    {
        return $this->fakeManager;
    }

    protected function newCommand(array $command): object
    {
        return (object) ['command' => $command];
    }
}

final class FakeMongoManager
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $commands = [];

    /**
     * @param  array<int, array<string, mixed>>  $indexes
     */
    public function __construct(private array $indexes) {}

    public function executeCommand(string $database, object $command): \Traversable
    {
        $payload = $command->command;
        $this->commands[] = $payload;

        if (array_key_exists('listIndexes', $payload)) {
            return new \ArrayIterator(array_map(
                static fn (array $index): object => (object) $index,
                $this->indexes
            ));
        }

        return new \ArrayIterator([]);
    }

    /**
     * @return array<int, string>
     */
    public function commandNames(): array
    {
        return array_map(
            static fn (array $command): string => (string) array_key_first($command),
            $this->commands
        );
    }
}
