<?php

namespace Fogeto\ServerOrchestrator\Contracts;

interface ApmStoreAvailability
{
    public function isAvailable(): bool;
}
