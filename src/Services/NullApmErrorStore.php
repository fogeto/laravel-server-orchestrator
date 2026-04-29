<?php

namespace Fogeto\ServerOrchestrator\Services;

use Fogeto\ServerOrchestrator\Contracts\IApmErrorStore;

class NullApmErrorStore implements IApmErrorStore
{
    public function tryEnqueue(array $event): bool
    {
        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecent(int $limit): array
    {
        return [];
    }

    public function clear(): void
    {
        //
    }
}
