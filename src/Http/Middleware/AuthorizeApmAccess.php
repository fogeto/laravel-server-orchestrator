<?php

namespace Fogeto\ServerOrchestrator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeApmAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401, ['Cache-Control' => 'no-store']);
        }
        $ability = trim((string) config('server-orchestrator.apm.authorization_ability', 'viewServerOrchestratorApm'));
        abort_if($ability === '', 403);
        Gate::forUser($user)->authorize($ability);

        return $next($request);
    }
}
