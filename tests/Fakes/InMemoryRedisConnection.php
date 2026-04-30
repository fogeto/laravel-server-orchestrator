<?php

namespace Fogeto\ServerOrchestrator\Tests\Fakes;

use Closure;
use Illuminate\Redis\Connections\Connection;

final class InMemoryRedisConnection extends Connection
{
    public function __construct(?InMemoryRedisClient $client = null)
    {
        $this->client = $client ?? new InMemoryRedisClient();
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
        $pipeline = new InMemoryRedisPipeline($this->client);
        $callback($pipeline);

        return $pipeline->results();
    }

    public function rawClient(): InMemoryRedisClient
    {
        return $this->client;
    }
}
