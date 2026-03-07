<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp;

use ElSchneider\ContentMcp\Support\AuthConfig;
use ElSchneider\ContentMcp\Support\StaticTokenUserResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Statamic\Providers\AddonServiceProvider;

final class ContentMcpServiceProvider extends AddonServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/statamic/content-mcp.php', 'statamic.content-mcp');

        config([
            'auth.guards.content-mcp-static-token' => array_merge([
                'driver' => 'content-mcp-static-token',
                'provider' => null,
            ], config('auth.guards.content-mcp-static-token', [])),
        ]);
    }

    public function boot(): void
    {
        parent::boot();

        Auth::viaRequest('content-mcp-static-token', function (Request $request) {
            $authConfig = app(AuthConfig::class);
            $configuredToken = $authConfig->staticToken();
            $incomingToken = $request->bearerToken();

            if (! is_string($configuredToken) || ! is_string($incomingToken)) {
                return null;
            }

            if (! hash_equals($configuredToken, $incomingToken)) {
                return null;
            }

            return app(StaticTokenUserResolver::class)->resolve($authConfig->staticTokenUser());
        });

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'content-mcp');
        $this->loadRoutesFrom(__DIR__.'/../routes/mcp.php');

        $this->publishes([
            __DIR__.'/../config/statamic/content-mcp.php' => config_path('statamic/content-mcp.php'),
        ], 'statamic-content-mcp-config');
    }

    public function bootAddon(): void
    {
        //
    }
}
