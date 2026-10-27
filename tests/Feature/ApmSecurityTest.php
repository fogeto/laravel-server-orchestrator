<?php

namespace Fogeto\ServerOrchestrator\Tests\Feature;

use Closure;
use Fogeto\ServerOrchestrator\Contracts\IApmErrorStore;
use Fogeto\ServerOrchestrator\Services\NullApmErrorStore;
use Fogeto\ServerOrchestrator\Services\ApmErrorBuffer;
use Fogeto\ServerOrchestrator\Tests\Fakes\RecordingApmStore;
use Fogeto\ServerOrchestrator\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Prometheus\CollectorRegistry;
use RuntimeException;

final class ApmSecurityTest extends TestCase
{
    private RecordingApmStore $store;
    private int $businessCalls = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('server-orchestrator.routes.middleware', [SubscriptionMiddleware::class]);
    }

    protected function defineRoutes($router): void
    {
        $router->post('/test/business', function () {
            $this->businessCalls++;
            return response()->json(['business' => 'ok'], 202);
        })->middleware(['api', 'auth', TokenMiddleware::class, 'can:review-business', SubscriptionMiddleware::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new RecordingApmStore();
        $this->app->instance(IApmErrorStore::class, $this->store);
        Gate::define('viewServerOrchestratorApm', fn ($user) => $user->getAuthIdentifier() === 1);
        Gate::define('review-business', fn ($user) => $user->getAuthIdentifier() === 1);
    }

    public function test_every_apm_alias_denies_anonymous_access_before_reading_storage(): void
    {
        foreach (['/apm/errors', '/__apm/errors', '/_apm/errors'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        $this->assertSame(0, $this->store->reads);
    }

    public function test_authenticated_user_without_service_wide_permission_is_denied(): void
    {
        $this->actingAs(new GenericUser(['id' => 2]));
        $this->getJson('/apm/errors')->assertForbidden();
        $this->assertSame(0, $this->store->reads);
    }

    public function test_anonymous_non_json_request_receives_401_without_a_login_route_dependency(): void
    {
        $this->get('/apm/errors')->assertUnauthorized();
        $this->assertSame(0, $this->store->reads);
    }

    public function test_authorized_user_can_read_each_alias(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]));
        foreach (['/apm/errors', '/__apm/errors', '/_apm/errors'] as $path) {
            $this->getJson($path)->assertOk()->assertExactJson([]);
        }
        $this->assertSame(3, $this->store->reads);
    }

    public function test_apm_feed_preserves_existing_subscription_middleware(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->getJson('/apm/errors', ['X-Test-Subscription' => 'inactive'])
            ->assertForbidden()->assertJsonPath('message', 'subscription-denied');
        $this->assertSame(0, $this->store->reads);
    }

    public function test_unavailable_apm_store_is_not_reported_as_an_empty_success(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->app->instance(IApmErrorStore::class, new NullApmErrorStore());
        $this->getJson('/apm/errors')->assertStatus(503);
    }

    public function test_missing_gate_denies_even_an_authenticated_user(): void
    {
        config(['server-orchestrator.apm.authorization_ability' => 'undefined-apm-ability']);
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->getJson('/apm/errors')->assertForbidden();
        $this->assertSame(0, $this->store->reads);
    }

    public function test_monitoring_failure_preserves_auth_token_permission_and_subscription_checks(): void
    {
        $this->app->bind(CollectorRegistry::class, fn () => throw new RuntimeException('synthetic-registry-failure'));
        $this->postJson('/test/business')->assertUnauthorized();
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->postJson('/test/business')->assertForbidden()->assertJsonPath('message', 'token-denied');
        $this->postJson('/test/business', [], [
            'Authorization' => 'Bearer synthetic-valid-token', 'X-Test-Subscription' => 'inactive',
        ])->assertForbidden()->assertJsonPath('message', 'subscription-denied');
        $this->actingAs(new GenericUser(['id' => 2]));
        $this->postJson('/test/business', [], ['Authorization' => 'Bearer synthetic-valid-token'])->assertForbidden();
        $this->assertSame(0, $this->businessCalls);
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->postJson('/test/business', [], ['Authorization' => 'Bearer synthetic-valid-token'])
            ->assertStatus(202)->assertExactJson(['business' => 'ok']);
        $this->assertSame(1, $this->businessCalls);
    }

    public function test_apm_resolution_failure_preserves_one_business_execution(): void
    {
        $this->app->forgetInstance(ApmErrorBuffer::class);
        $this->app->bind(ApmErrorBuffer::class, fn () => throw new RuntimeException('synthetic-apm-resolution-failure'));
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->postJson('/test/business', [], ['Authorization' => 'Bearer synthetic-valid-token'])
            ->assertStatus(202)->assertExactJson(['business' => 'ok']);
        $this->assertSame(1, $this->businessCalls);
    }

    public function test_apm_resolution_failure_does_not_bypass_authorization(): void
    {
        $this->app->forgetInstance(ApmErrorBuffer::class);
        $this->app->bind(ApmErrorBuffer::class, fn () => throw new RuntimeException('synthetic-apm-resolution-failure'));
        $this->getJson('/apm/errors')->assertUnauthorized();
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->getJson('/apm/errors')->assertStatus(503);
    }
}

final class SubscriptionMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        return $request->header('X-Test-Subscription') === 'inactive'
            ? response()->json(['message' => 'subscription-denied'], 403) : $next($request);
    }
}

final class TokenMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        return $request->bearerToken() !== 'synthetic-valid-token'
            ? response()->json(['message' => 'token-denied'], 403) : $next($request);
    }
}
