<?php

declare(strict_types=1);

use Illuminate\Routing\Route;

/*
 * Production deploys run `php artisan route:cache`. Cached routes skip every
 * provider's route registration, so the consent middleware we attach to
 * Passport's route and the closure routes of laravel/mcp must survive serialization.
 */
it('connects Claude and serves MCP calls from cached routes', function () {
    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();
    $routes->refreshActionLookups();

    foreach ($routes as $route) {
        /** @var Route $route */
        $route->prepareForSerialization();
    }

    // What `php artisan route:cache` writes.
    $cache = app()->getCachedRoutesPath();
    file_put_contents($cache, '<?php app("router")->setCompiledRoutes('.var_export($routes->compile(), true).');');

    try {
        $this->refreshApplication();
        $this->preventSavingStacheItemsToDisk();
        $this->artisan('migrate');
        require $cache;

        expect(app()->routesAreCached())->toBeTrue()
            ->and(app('router')->getRoutes()->getByName('statamic.cp.content-mcp.connections.destroy'))->not->toBeNull();

        siteContent();
        $this->getJson('/.well-known/oauth-protected-resource/mcp/content')->assertOk()->assertJsonPath('resource', url('/mcp/content'));

        $tokens = connectClaude(editor(['access content mcp', 'view blog entries']));

        mcp($tokens['access_token'], 'tools/call', ['name' => 'get_overview', 'arguments' => []])
            ->assertOk()
            ->assertJsonPath('result.structuredContent.collections.0.handle', 'blog');
    } finally {
        unlink($cache);
    }
});
