<?php

namespace Fogeto\ServerOrchestrator\Tests\Unit;

use Fogeto\ServerOrchestrator\Providers\ServerOrchestratorServiceProvider;
use Fogeto\ServerOrchestrator\Tests\TestCase;
use ReflectionMethod;

final class ServerOrchestratorServiceProviderTest extends TestCase
{
    public function test_package_config_contains_current_apm_store_and_service_defaults(): void
    {
        $config = require __DIR__ . '/../../config/server-orchestrator.php';

        $this->assertSame('mongo', $config['apm']['store']);
        $this->assertArrayHasKey('service', $config['apm']);
        $this->assertArrayHasKey('scope_by_service', $config['apm']);
        $this->assertArrayHasKey('redis', $config['apm']);
        $this->assertArrayHasKey('mongo', $config['apm']);
    }

    public function test_recursive_config_merge_adds_new_apm_defaults_to_old_published_config(): void
    {
        $packageConfig = require __DIR__ . '/../../config/server-orchestrator.php';
        $oldPublishedConfig = [
            'apm' => [
                'mongo' => [
                    'connection_string' => 'mongodb://example',
                    'database' => 'legacy_db',
                    'collection' => 'ApmErrors',
                ],
            ],
        ];

        $merged = $this->callPrivateProviderMethod('mergeConfigArrays', [$packageConfig, $oldPublishedConfig]);

        $this->assertSame('mongo', $merged['apm']['store']);
        $this->assertArrayHasKey('service', $merged['apm']);
        $this->assertSame('mongodb://example', $merged['apm']['mongo']['connection_string']);
        $this->assertSame('legacy_db', $merged['apm']['mongo']['database']);
        $this->assertArrayHasKey('redis', $merged['apm']);
    }

    public function test_redis_client_override_accepts_predis(): void
    {
        config([
            'database.redis.client' => 'phpredis',
            'server-orchestrator.redis_client' => 'predis',
        ]);

        $this->callPrivateProviderMethod('configureRedisClient');

        $this->assertSame('predis', config('database.redis.client'));
    }

    public function test_redis_client_override_accepts_phpredis(): void
    {
        config([
            'database.redis.client' => 'predis',
            'server-orchestrator.redis_client' => 'phpredis',
        ]);

        $this->callPrivateProviderMethod('configureRedisClient');

        $this->assertSame('phpredis', config('database.redis.client'));
    }

    public function test_redis_client_override_ignores_invalid_values(): void
    {
        config([
            'database.redis.client' => 'predis',
            'server-orchestrator.redis_client' => 'invalid',
        ]);

        $this->callPrivateProviderMethod('configureRedisClient');

        $this->assertSame('predis', config('database.redis.client'));
    }

    private function callPrivateProviderMethod(string $method, array $arguments = []): mixed
    {
        $provider = new ServerOrchestratorServiceProvider($this->app);
        $reflection = new ReflectionMethod($provider, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($provider, $arguments);
    }
}
