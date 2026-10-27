<?php

namespace Fogeto\ServerOrchestrator\Tests\Unit;

use Fogeto\ServerOrchestrator\Adapters\FaultTolerantAdapter;
use Fogeto\ServerOrchestrator\Adapters\NullAdapter;
use Fogeto\ServerOrchestrator\Adapters\PredisAdapter;
use Fogeto\ServerOrchestrator\Http\Controllers\MetricsController;
use Fogeto\ServerOrchestrator\Http\Middleware\PrometheusMiddleware;
use Fogeto\ServerOrchestrator\Listeners\HttpClientListener;
use Fogeto\ServerOrchestrator\Services\MonitoringErrorReporter;
use Fogeto\ServerOrchestrator\Services\SqlQueryMetricsRecorder;
use Fogeto\ServerOrchestrator\Services\RedisApmErrorStore;
use Fogeto\ServerOrchestrator\Tests\Fakes\InMemoryRedisConnection;
use Fogeto\ServerOrchestrator\Tests\Fakes\TransientMetricsStorage;
use Fogeto\ServerOrchestrator\Tests\TestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Mockery;
use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use RuntimeException;

final class MonitoringResilienceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['server-orchestrator.system_metrics' => array_fill_keys(
            ['php_info', 'uptime', 'memory', 'database', 'opcache', 'health'], false
        )]);
    }

    public function test_failed_scrape_returns_503_without_internal_details(): void
    {
        $registry = new CollectorRegistry(new FaultTolerantAdapter(new TransientMetricsStorage()), false);
        $response = (new MetricsController($registry))->index(Request::create('/metrics'));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringNotContainsString('synthetic-storage-failure', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_setup_fallback_does_not_produce_a_successful_empty_scrape(): void
    {
        $response = (new MetricsController(new CollectorRegistry(new NullAdapter(), false)))
            ->index(Request::create('/metrics'));
        $this->assertSame(503, $response->getStatusCode());
    }

    public function test_registry_resolution_failure_is_a_controlled_scrape_failure(): void
    {
        $this->breakRegistryResolution();
        $response = $this->app->make(MetricsController::class)->index(Request::create('/metrics'));
        $this->assertSame(503, $response->getStatusCode());
    }

    public function test_registry_resolution_failure_preserves_one_business_execution(): void
    {
        $this->breakRegistryResolution();
        $calls = 0;
        $expected = new Response('business-result', 202);
        $actual = $this->app->make(PrometheusMiddleware::class)->handle(
            Request::create('/business', 'POST'),
            function () use (&$calls, $expected) { $calls++; return $expected; }
        );
        $this->assertSame(1, $calls);
        $this->assertSame($expected, $actual);
    }

    public function test_registry_resolution_failure_preserves_the_business_exception(): void
    {
        $this->breakRegistryResolution();
        $expected = new RuntimeException('business-failure');
        $calls = 0;
        try {
            $this->app->make(PrometheusMiddleware::class)->handle(
                Request::create('/business'),
                function () use (&$calls, $expected) { $calls++; throw $expected; }
            );
            $this->fail('Business exception was swallowed.');
        } catch (RuntimeException $e) {
            $this->assertSame($expected, $e);
        }
        $this->assertSame(1, $calls);
    }

    public function test_sql_listener_isolates_recorder_resolution_failure(): void
    {
        $this->breakRegistryResolution();
        Event::dispatch(new QueryExecuted('select 1', [], 1, new \Illuminate\Database\Connection(null)));
        $this->assertTrue(true); // Reaching here means the successful query was not replaced by monitoring failure.
    }

    public function test_sql_reporting_failure_cannot_escape_the_recorder(): void
    {
        $this->throwWhenReporting();
        $recorder = new SqlQueryMetricsRecorder($this->registrationFailure());
        $recorder->recordError('select * from review_records');
        $recorder->recordDuration('select * from review_records', 0.01);
        $this->assertTrue(true);
    }

    public function test_reporting_reentrancy_is_bounded_before_the_callback_runs(): void
    {
        $recorder = new SqlQueryMetricsRecorder($this->registrationFailure());
        $calls = 0;
        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->andReturnUsing(function () use (&$calls, $recorder) {
            $calls++;
            if ($calls < 3) { $recorder->recordError('select * from review_records'); }
        });
        $this->app->instance(ExceptionHandler::class, $handler);
        $recorder->recordError('select * from review_records');
        $this->assertSame(1, $calls);
    }

    public function test_reporting_cooldown_recovers_without_restart(): void
    {
        $now = 100.0;
        $reporter = new MonitoringErrorReporter(60.0, function () use (&$now) { return $now; });
        $calls = 0;
        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->andReturnUsing(function () use (&$calls) { $calls++; });
        $this->app->instance(ExceptionHandler::class, $handler);
        $reporter->report(new RuntimeException('first'));
        $reporter->report(new RuntimeException('suppressed'));
        $now = 161.0;
        $reporter->report(new RuntimeException('later'));
        $this->assertSame(2, $calls);
    }

    public function test_circuit_bounds_attempts_and_resumes_metric_recording_after_recovery(): void
    {
        $now = 100.0;
        $storage = new TransientMetricsStorage();
        $adapter = new FaultTolerantAdapter($storage, 5.0, null, function () use (&$now) { return $now; });
        $registry = new CollectorRegistry($adapter, false);
        $counter = $registry->getOrRegisterCounter('review', 'requests_total', 'Requests');
        for ($i = 0; $i < 5; $i++) { $counter->inc(); }
        $this->assertSame(1, $storage->attempts);
        $storage->unavailable = false;
        $now = 106.0;
        $counter->inc();
        $text = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        $this->assertStringContainsString('review_requests_total 1', $text);
    }

    public function test_healthy_scrape_keeps_prometheus_output(): void
    {
        $storage = new TransientMetricsStorage();
        $storage->unavailable = false;
        $registry = new CollectorRegistry(new FaultTolerantAdapter($storage), false);
        $registry->getOrRegisterCounter('review', 'requests_total', 'Requests')->inc();
        $response = (new MetricsController($registry))->index(Request::create('/metrics'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('review_requests_total 1', $response->getContent());
    }

    public function test_failed_storage_creation_is_retried_after_cooldown(): void
    {
        $now = 100.0;
        $attempts = 0;
        $adapter = new FaultTolerantAdapter(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) { throw new RuntimeException('synthetic-connection-creation-failure'); }
            return new InMemory();
        }, 5.0, null, function () use (&$now) { return $now; });
        $registry = new CollectorRegistry($adapter, false);
        $counter = $registry->getOrRegisterCounter('review', 'recovered_total', 'Recovered');
        $counter->inc(); $counter->inc();
        $this->assertSame(1, $attempts);
        $now = 106.0;
        $counter->inc();
        $text = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        $this->assertStringContainsString('review_recovered_total 1', $text);
        $this->assertSame(2, $attempts);
    }

    public function test_redis_gauge_honors_increment_decrement_and_set(): void
    {
        $connection = new InMemoryRedisConnection();
        $registry = new CollectorRegistry(new PredisAdapter($connection, 'prometheus:gauge_test:', null), false);
        $gauge = $registry->getOrRegisterGauge('review', 'in_progress', 'In progress');
        $gauge->inc(); $gauge->inc(); $gauge->dec();
        $this->assertSame(1.0, array_sum($connection->rawClient()->hashes['prometheus:gauge_test:gauges:review_in_progress']));
        $gauge->set(7); $gauge->incBy(0.5);
        $this->assertSame(7.5, array_sum($connection->rawClient()->hashes['prometheus:gauge_test:gauges:review_in_progress']));
    }

    public function test_all_metric_metadata_recovers_after_a_write_or_read_outage(): void
    {
        foreach (['write', 'read'] as $failure) {
            $now = 100.0;
            $connection = new \Fogeto\ServerOrchestrator\Tests\Fakes\FlakyInMemoryRedisConnection(null, false);
            $registry = new CollectorRegistry(new FaultTolerantAdapter(
                new PredisAdapter($connection, 'prometheus:multi_recovery_' . $failure . ':', null),
                5.0, null, function () use (&$now) { return $now; }
            ), false);
            $first = $registry->getOrRegisterCounter('review', 'first_total', 'First');
            $second = $registry->getOrRegisterCounter('review', 'second_total', 'Second');
            $first->inc(); $second->inc();
            // Simulate a Redis restart using only the in-memory fake, not a database.
            $connection->rawClient()->hashes = [];
            $connection->failNextPipeline();
            if ($failure === 'write') {
                $first->inc();
            } else {
                try { $registry->getMetricFamilySamples(); }
                catch (\Fogeto\ServerOrchestrator\Exceptions\MetricsStorageUnavailable $e) {}
            }
            $second->inc(); // Circuit is open; this metric never reaches Redis.
            $now = 106.0;
            $first->inc(); $second->inc();
            $text = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
            $this->assertStringContainsString('review_first_total 1', $text, $failure);
            $this->assertStringContainsString('review_second_total 1', $text, $failure);
        }
    }

    public function test_http_listener_reporting_failure_cannot_replace_an_outbound_response(): void
    {
        $this->breakRegistryResolution();
        $this->throwWhenReporting();
        $listener = new HttpClientListener();
        $request = new \Illuminate\Http\Client\Request(new \GuzzleHttp\Psr7\Request('GET', 'https://example.test'));
        $response = new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, [], 'ok'));
        $listener->handleRequestSending(new \Illuminate\Http\Client\Events\RequestSending($request));
        $listener->handleResponseReceived(new \Illuminate\Http\Client\Events\ResponseReceived($request, $response));
        $this->assertSame('ok', $response->body());
    }

    public function test_redis_apm_read_and_reporting_failure_are_isolated(): void
    {
        $this->throwWhenReporting();
        $connection = new class extends \Illuminate\Redis\Connections\Connection {
            public function createSubscription($channels, \Closure $callback, $method = 'subscribe'): void {}
            public function command($method, array $parameters = []) { throw new RuntimeException('synthetic-redis-failure'); }
        };
        $store = new RedisApmErrorStore($connection);
        $this->assertSame([], $store->getRecent(1));
    }

    private function breakRegistryResolution(): void
    {
        $this->app->forgetInstance(CollectorRegistry::class);
        $this->app->bind(CollectorRegistry::class, function () { throw new RuntimeException('registry-resolution-failure'); });
    }

    private function throwWhenReporting(): void
    {
        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->andThrow(new RuntimeException('reporter-failure'));
        $this->app->instance(ExceptionHandler::class, $handler);
    }

    private function registrationFailure(): CollectorRegistry
    {
        return new class(new InMemory(), false) extends CollectorRegistry {
            public function getOrRegisterCounter(string $namespace, string $name, string $help, array $labels = []): Counter
            {
                throw new RuntimeException('metric-registration-failure');
            }
        };
    }
}
