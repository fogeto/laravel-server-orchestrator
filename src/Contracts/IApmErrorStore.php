<?php

namespace Fogeto\ServerOrchestrator\Contracts;

interface IApmErrorStore
{
    public function tryEnqueue(array $event): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecent(int $limit): array;

    public function clear(): void;
}