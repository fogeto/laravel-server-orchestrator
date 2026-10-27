<?php

namespace Fogeto\ServerOrchestrator\Services;

use Fogeto\ServerOrchestrator\Contracts\IApmErrorStore;
use Fogeto\ServerOrchestrator\Contracts\ApmStoreAvailability;

class NullApmErrorStore implements IApmErrorStore, ApmStoreAvailability
{
    public function isAvailable(): bool { return false; }

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
