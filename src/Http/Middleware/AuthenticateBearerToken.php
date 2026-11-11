<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Http\Middleware;

use Closure;
use ElSchneider\ContentMcp\ContentMcpServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the MCP request as the token's Statamic user, so User::current(),
 * auth()->user() and the Gate (and with them site policies and hooks) see that user.
 */
final class AuthenticateBearerToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse(ContentMcpServiceProvider::GUARD);

        if (! $user = Auth::user()) {
            // laravel/mcp adds the WWW-Authenticate header that points the client to OAuth discovery.
            return response()->json(['error' => 'invalid_token'], 401);
        }

        // Checked on every request so removing the permission from a role cuts access at once.
        if (Gate::forUser($user)->denies(ContentMcpServiceProvider::PERMISSION)) {
            return response()->json([
                'error' => 'insufficient_permission',
                'error_description' => 'This Statamic account may not use Content MCP.',
            ], 403);
        }

        return $next($request);
    }
}
