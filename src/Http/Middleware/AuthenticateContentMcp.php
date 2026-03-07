<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Http\Middleware;

use Closure;
use ElSchneider\ContentMcp\Support\AuthConfig;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\SanctumServiceProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateContentMcp
{
    public function __construct(private readonly AuthConfig $authConfig) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = match ($this->authConfig->driver()) {
            'sanctum' => 'sanctum',
            'static_token' => 'content-mcp-static-token',
            default => throw new RuntimeException('Unsupported content MCP auth driver.'),
        };

        if ($guard === 'sanctum' && ! class_exists(SanctumServiceProvider::class)) {
            throw new RuntimeException('The content MCP Sanctum auth driver requires laravel/sanctum.');
        }

        if ($guard === 'content-mcp-static-token'
            && ($this->authConfig->staticToken() === null || $this->authConfig->staticTokenUser() === null)) {
            throw new RuntimeException('The content MCP static_token auth driver requires both token and user.');
        }

        if (! Auth::guard($guard)->check()) {
            throw new AuthenticationException('Unauthenticated.', [$guard]);
        }

        Auth::shouldUse($guard);
        $request->setUserResolver(fn () => Auth::guard($guard)->user());

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
