<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp;

use Statamic\Providers\AddonServiceProvider;

final class ContentMcpServiceProvider extends AddonServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/statamic/content-mcp.php', 'statamic.content-mcp');
    }

    public function boot(): void
    {
        parent::boot();

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
