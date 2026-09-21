<?php

namespace Fogeto\ServerOrchestrator\Tests\Fakes;

use Closure;
use Illuminate\Redis\Connections\Connection;
use RuntimeException;

final class FlakyInMemoryRedisConnection extends Connection
{
    private bool $failNextPipeline;

    public function __construct(?InMemoryRedisClient $client = null, bool $failNextPipeline = true)
    {
        $this->client = $client ?? new InMemoryRedisClient();
        $this->failNextPipeline = $failNextPipeline;
    }

    public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void
    {
        throw new \BadMethodCallException('Subscriptions are not supported by the in-memory test Redis connection.');
    }

    /**
     * @return array<int, mixed>
     */
    public function pipeline(callable $callback): array
    {
        if ($this->failNextPipeline) {
            $this->failNextPipeline = false;

            throw new RuntimeException('Redis is temporarily unavailable.');
        }

        $pipeline = new InMemoryRedisPipeline($this->client);
        $callback($pipeline);

        return $pipeline->results();
    }

    public function rawClient(): InMemoryRedisClient
    {
        return $this->client;
    }

    public function failNextPipeline(): void
    {
        $this->failNextPipeline = true;
    }
}
