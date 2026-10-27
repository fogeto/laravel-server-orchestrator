<?php

namespace Fogeto\ServerOrchestrator\Tests\Unit;

use Fogeto\ServerOrchestrator\Services\ApmErrorBuffer;
use Fogeto\ServerOrchestrator\Tests\Fakes\RecordingApmStore;
use Fogeto\ServerOrchestrator\Tests\TestCase;

final class ApmRedactionTest extends TestCase
{
    public function test_nested_json_and_both_header_directions_redact_secrets(): void
    {
        $store = new RecordingApmStore();
        (new ApmErrorBuffer($store))->captureIncoming([
            'statusCode' => 401,
            'requestBody' => '{"username":"alice","password":"synthetic-password","profile":{"apiKey":"synthetic-key","tokens":["synthetic-token"]}}',
            'responseBody' => '{"error":"denied","access_token":"synthetic-response-token"}',
            'requestHeaders' => ['X-XSRF-Token' => 'synthetic-xsrf', 'Authorization' => 'Bearer synthetic-header-token'],
            'responseHeaders' => ['Set-Cookie' => 'session=synthetic-session; HttpOnly'],
        ]);
        $event = $store->events[0];
        $this->assertStringContainsString('alice', $event['requestBody']);
        $this->assertStringContainsString('***REDACTED***', $event['requestBody']);
        $this->assertStringNotContainsString('synthetic-', json_encode($event));
    }

    public function test_form_and_query_parameters_redact_nested_and_custom_fields(): void
    {
        config(['server-orchestrator.apm.sensitive_fields' => ['crm_credential']]);
        $store = new RecordingApmStore();
        (new ApmErrorBuffer($store))->captureIncoming([
            'statusCode' => 400,
            'requestBody' => 'username=alice&password=synthetic-password&crm_credential=synthetic-custom',
            'queryString' => 'filter[token]=synthetic-query&q=hello',
        ]);
        $event = $store->events[0];
        $this->assertStringContainsString('alice', $event['requestBody']);
        $this->assertStringContainsString('hello', $event['queryString']);
        $this->assertStringNotContainsString('synthetic-', json_encode($event));
    }

    public function test_unstructured_and_malformed_payloads_are_omitted(): void
    {
        foreach (['synthetic-unlabelled-secret', '{"password":"synthetic-malformed', "\xffsynthetic-binary-secret"] as $body) {
            $store = new RecordingApmStore();
            (new ApmErrorBuffer($store))->captureIncoming([
                'statusCode' => 500, 'requestBody' => $body, 'responseBody' => $body,
            ]);
            $encoded = json_encode($store->events[0]);
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString('synthetic-', $encoded);
            $this->assertStringNotContainsString('base64_prefix', $encoded);
        }
    }

    public function test_outgoing_url_credentials_and_query_tokens_are_redacted(): void
    {
        $store = new RecordingApmStore();
        (new ApmErrorBuffer($store))->captureOutgoing([
            'statusCode' => 500,
            'url' => 'https://synthetic-user:synthetic-pass@example.test/path?token=synthetic-url-token&q=hello',
        ]);
        $event = $store->events[0];
        $this->assertStringContainsString('example.test/path', $event['path']);
        $this->assertStringContainsString('hello', $event['path']);
        $this->assertStringNotContainsString('synthetic-', json_encode($event));
    }

    public function test_historical_records_are_sanitized_before_the_feed_returns_them(): void
    {
        $store = new RecordingApmStore();
        $store->events = [[
            'statusCode' => 500,
            'requestBody' => '{"password":"synthetic-old-password","username":"alice"}',
            'responseBody' => '{"token":"synthetic-old-token"}',
            'message' => 'synthetic-old-token',
            'responseHeaders' => ['Set-Cookie' => 'session=synthetic-old-cookie'],
            'queryString' => 'token=synthetic-old-query',
        ]];
        $events = (new ApmErrorBuffer($store))->getAll(1);
        $this->assertCount(1, $events);
        $this->assertStringContainsString('alice', $events[0]['requestBody']);
        $this->assertStringNotContainsString('synthetic-', json_encode($events));
    }

    public function test_urls_inside_headers_and_json_do_not_retain_credentials_or_query_secrets(): void
    {
        $store = new RecordingApmStore();
        (new ApmErrorBuffer($store))->captureIncoming([
            'statusCode' => 400,
            'responseHeaders' => ['Location' => 'https://example.test/callback?token=synthetic-location-token'],
            'requestBody' => '{"callback":"https://synthetic-user:synthetic-pass@example.test/callback?token=synthetic-json-token"}',
        ]);
        $this->assertStringNotContainsString('synthetic-', json_encode($store->events));
        $this->assertStringContainsString('example.test', json_encode($store->events));
    }

    public function test_relative_link_and_protocol_relative_urls_are_redacted_on_capture_and_read(): void
    {
        $raw = [
            'statusCode' => 400,
            'responseHeaders' => [
                'Location' => '/callback?token=synthetic-relative-token',
                'Link' => '<https://example.test/page?api_key=synthetic-link-key>; rel="next"',
            ],
            'requestBody' => '{"callback":"//user:synthetic-userinfo@example.test/path?access_token=synthetic-json-token"}',
        ];
        $store = new RecordingApmStore();
        $buffer = new ApmErrorBuffer($store);
        $buffer->captureIncoming($raw);
        $this->assertStringNotContainsString('synthetic-', json_encode($store->events));
        $this->assertStringContainsString('example.test/path', $store->events[0]['requestBody']);
        $this->assertIsArray(json_decode($store->events[0]['requestBody'], true));
        $store->events = [$raw];
        $events = $buffer->getAll(1);
        $this->assertStringNotContainsString('synthetic-', json_encode($events));
        $this->assertStringContainsString('/callback?', $events[0]['responseHeaders']['Location']);
    }
}
