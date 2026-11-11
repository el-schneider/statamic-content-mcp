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
        // league/oauth2-server also accepts `plain`, and treats a missing method as plain.
        if (! $request->filled('code_challenge') || $request->query('code_challenge_method') !== 'S256') {
            return response()->json([
                'error' => 'invalid_request',
                'error_description' => 'PKCE with code_challenge_method S256 is required.',
            ], 400);
        }

        $guard = Auth::guard(config()->string('statamic.users.guards.cp'));
        $prompts = explode(' ', $request->string('prompt')->toString());

        // With prompt=none Passport answers a guest itself, with login_required.
        if (! in_array('none', $prompts, true)) {
            // Passport's own prompt=login handling ends in a redirect to route('login'),
            // which Statamic sites lack. Same handshake through the CP login instead: log
            // out once; the `promptedForLogin` flag tells Passport the login has happened.
            if (in_array('login', $prompts, true) && ! $request->session()->get('promptedForLogin', false)) {
                $guard->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            // Passport would throw an AuthenticationException, which Laravel turns into a
            // redirect to route('login'). The CP login sends the editor back here through
            // redirect()->intended().
            if ($guard->guest()) {
                $request->session()->put('promptedForLogin', true);

                return redirect()->guest(cp_route('login'));
            }
        }

        $user = $guard->user();

        if ($user !== null && Gate::forUser($user)->denies(ContentMcpServiceProvider::PERMISSION)) {
            return response()->view('content-mcp::forbidden', ['user' => User::fromUser($user)], 403);
        }

        return $next($request);
    }
}
