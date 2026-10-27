<?php

namespace Fogeto\ServerOrchestrator\Tests\Fakes;

use Fogeto\ServerOrchestrator\Contracts\IApmErrorStore;

final class RecordingApmStore implements IApmErrorStore
{
    public array $events = [];
    public int $reads = 0;

    public function tryEnqueue(array $event): bool
    {
        $this->events[] = $event;
        return true;
    }

    public function getRecent(int $limit): array
    {
        $this->reads++;
        return array_slice($this->events, 0, $limit);
    }

    public function clear(): void {}
}
