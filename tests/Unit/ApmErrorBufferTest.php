<?php

namespace Fogeto\ServerOrchestrator\Tests\Unit;

use Fogeto\ServerOrchestrator\Contracts\IApmErrorStore;
use Fogeto\ServerOrchestrator\Services\ApmErrorBuffer;
use Fogeto\ServerOrchestrator\Tests\TestCase;

final class ApmErrorBufferTest extends TestCase
{
    public function test_capture_incoming_adds_service_redacts_headers_and_truncates_payloads(): void
    {
        config([
            'server-orchestrator.apm.service' => 'ikbackend',
            'server-orchestrator.apm.max_body_size' => 10,
            'server-orchestrator.apm.max_message_length' => 6,
        ]);

        $store = new class implements IApmErrorStore {
            /** @var array<int, array<string, mixed>> */
            public array $events = [];

            public function tryEnqueue(array $event): bool
            {
                $this->events[] = $event;

                return true;
            }

            public function getRecent(int $limit): array
            {
                return array_slice(array_reverse($this->events), 0, $limit);
            }

            public function clear(): void
            {
                $this->events = [];
            }
        };

        $buffer = new ApmErrorBuffer($store);
        $buffer->captureIncoming([
            'path' => '/api/test',
            'method' => 'POST',
            'statusCode' => 500,
            'requestBody' => '123456789012345',
            'responseBody' => 'internal-server-error',
            'requestHeaders' => [
                'authorization' => 'Bearer secret',
                'x-request-id' => 'request-1',
            ],
            'responseHeaders' => [
                'content-type' => 'application/json',
            ],
            'durationMs' => 12.345,
        ]);

        $this->assertCount(1, $store->events);
        $event = $store->events[0];

        $this->assertSame('ikbackend', $event['service']);
        $this->assertSame('/api/test', $event['path']);
        $this->assertSame(500, $event['statusCode']);
        $this->assertSame('Internal Server Error', $event['errorType']);
        $this->assertSame('intern', $event['message']);
        $this->assertSame('1234567890', $event['requestBody']);
        $this->assertSame('internal-s', $event['responseBody']);
        $this->assertSame('***REDACTED***', $event['requestHeaders']['authorization']);
        $this->assertSame('request-1', $event['requestHeaders']['x-request-id']);
        $this->assertSame(12.35, $event['durationMs']);
    }
}
