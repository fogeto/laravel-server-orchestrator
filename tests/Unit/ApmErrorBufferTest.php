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

        $store = new FakeApmErrorStore();

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

    public function test_capture_incoming_replaces_invalid_utf8_body_with_safe_placeholder(): void
    {
        config([
            'server-orchestrator.apm.service' => 'ikbackend',
            'server-orchestrator.apm.max_body_size' => 1000,
            'server-orchestrator.apm.max_message_length' => 200,
        ]);

        $store = new FakeApmErrorStore();
        $buffer = new ApmErrorBuffer($store);

        $buffer->captureIncoming([
            'path' => '/',
            'method' => 'POST',
            'statusCode' => 404,
            'requestBody' => "\x0c\xff\x01\x00\x00\x01\x00\x00application-dns-message",
            'responseBody' => 'not found',
            'requestHeaders' => [
                'content-type' => 'application/dns-message',
            ],
            'responseHeaders' => [],
            'userAgent' => "Go-http-client/1.1",
        ]);

        $event = $store->events[0];

        $this->assertStringStartsWith('[non-utf8 string omitted;', $event['requestBody']);
        $this->assertStringContainsString('base64_prefix=', $event['requestBody']);
        $this->assertSame(1, preg_match('//u', $event['requestBody']));
        $this->assertSame('not found', $event['responseBody']);
    }

    public function test_truncation_keeps_multibyte_utf8_valid(): void
    {
        config([
            'server-orchestrator.apm.service' => 'ikbackend',
            'server-orchestrator.apm.max_body_size' => 5,
            'server-orchestrator.apm.max_message_length' => 5,
        ]);

        $store = new FakeApmErrorStore();
        $buffer = new ApmErrorBuffer($store);
        $threeTurkishChars = hex2bin('c3a7c3a7c3a7');

        $buffer->captureIncoming([
            'path' => '/utf8',
            'method' => 'GET',
            'statusCode' => 500,
            'requestBody' => $threeTurkishChars,
            'responseBody' => $threeTurkishChars,
            'requestHeaders' => [],
            'responseHeaders' => [],
        ]);

        $event = $store->events[0];

        $this->assertSame(hex2bin('c3a7c3a7'), $event['requestBody']);
        $this->assertSame(hex2bin('c3a7c3a7'), $event['responseBody']);
        $this->assertSame(1, preg_match('//u', $event['requestBody']));
        $this->assertSame(1, preg_match('//u', $event['responseBody']));
    }
}

final class FakeApmErrorStore implements IApmErrorStore
{
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
}
