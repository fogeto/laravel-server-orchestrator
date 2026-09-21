<?php

namespace Fogeto\ServerOrchestrator\Tests\Unit;

use Fogeto\ServerOrchestrator\Adapters\FaultTolerantAdapter;
use Fogeto\ServerOrchestrator\Http\Middleware\PrometheusMiddleware;
use Fogeto\ServerOrchestrator\Tests\Fakes\FailingMetricsAdapter;
use Fogeto\ServerOrchestrator\Tests\TestCase;
use Illuminate\Http\Request;
use Prometheus\CollectorRegistry;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class PrometheusMiddlewareTest extends TestCase
{
    public function test_storage_failure_preserves_the_response_and_executes_the_request_once(): void
    {
        $storage = new FailingMetricsAdapter();
        $registry = new CollectorRegistry(new FaultTolerantAdapter($storage), false);
        $middleware = new PrometheusMiddleware($registry);
        $request = Request::create('/protected-operation', 'POST');
        $expectedResponse = new Response('business-result', 202, ['X-Business-Result' => 'preserved']);
        $calls = 0;

        $response = $middleware->handle($request, function () use (&$calls, $expectedResponse): Response {
            $calls++;

            return $expectedResponse;
        });

        $this->assertSame(1, $calls);
        $this->assertSame($expectedResponse, $response);
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('business-result', $response->getContent());
        $this->assertSame('preserved', $response->headers->get('X-Business-Result'));
        $this->assertSame(2, $storage->gaugeWrites);
        $this->assertSame(1, $storage->histogramWrites);
        $this->assertSame(1, $storage->counterWrites);
    }

    public function test_storage_failure_does_not_replace_the_business_exception(): void
    {
        $storage = new FailingMetricsAdapter();
        $registry = new CollectorRegistry(new FaultTolerantAdapter($storage), false);
        $middleware = new PrometheusMiddleware($registry);
        $request = Request::create('/protected-operation', 'POST');
        $expectedException = new RuntimeException('Business request failed.');
        $calls = 0;

        try {
            $middleware->handle($request, function () use (&$calls, $expectedException): Response {
                $calls++;

                throw $expectedException;
            });

            $this->fail('The business exception was not propagated.');
        } catch (RuntimeException $actualException) {
            $this->assertSame($expectedException, $actualException);
        }

        $this->assertSame(1, $calls);
        $this->assertSame(2, $storage->gaugeWrites);
        $this->assertSame(0, $storage->histogramWrites);
        $this->assertSame(0, $storage->counterWrites);
    }
}
