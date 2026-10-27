<?php

namespace Fogeto\ServerOrchestrator\Services;

use Closure;
use Throwable;

final class MonitoringErrorReporter
{
    private bool $reporting = false;
    private float $nextReportAt = 0.0;
    private Closure $clock;

    public function __construct(private float $cooldownSeconds = 60.0, ?Closure $clock = null)
    {
        $this->cooldownSeconds = is_finite($cooldownSeconds) ? max(0.0, $cooldownSeconds) : 60.0;
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    public function report(Throwable $exception): void
    {
        $now = ($this->clock)();
        if ($this->reporting || $now < $this->nextReportAt) {
            return;
        }
        $this->reporting = true;
        $this->nextReportAt = $now + $this->cooldownSeconds;
        try {
            report($exception);
        } catch (Throwable) {
            // A failing logger/Sentry transport cannot replace application results.
        } finally {
            $this->reporting = false;
        }
    }
}
