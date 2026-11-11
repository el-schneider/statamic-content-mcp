<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Http\Middleware;

use Closure;
use ElSchneider\ContentMcp\ContentMcpServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attached to Passport's GET /oauth/authorize.
 */
final class EnsureEditorCanConsent
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard(config()->string('statamic.users.guards.cp'));

        // Passport would throw an AuthenticationException, which Laravel turns into a
        // redirect to route('login'). Statamic sites have no such route; the CP login
        // sends the editor back here through redirect()->intended().
        if (! $user = $guard->user()) {
            return redirect()->guest(cp_route('login'));
        }

        if (Gate::forUser($user)->denies(ContentMcpServiceProvider::PERMISSION)) {
            return response()->view('content-mcp::forbidden', ['user' => User::fromUser($user)], 403);
        }

        return $next($request);
    }
}
