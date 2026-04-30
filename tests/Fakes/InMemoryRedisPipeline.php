<?php

namespace Fogeto\ServerOrchestrator\Tests\Fakes;

final class InMemoryRedisPipeline
{
    /** @var array<int, mixed> */
    private array $results = [];

    public function __construct(private InMemoryRedisClient $client) {}

    public function __call(string $method, array $parameters): self
    {
        $this->results[] = $this->client->{$method}(...$parameters);

        return $this;
    }

    /**
     * @return array<int, mixed>
     */
    public function results(): array
    {
        return $this->results;
    }
}
