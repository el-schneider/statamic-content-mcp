<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp;

use Statamic\Providers\AddonServiceProvider;

final class ContentMcpServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'input' => [
            'resources/js/addon.ts',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__ . '/../config/statamic/content-mcp.php', 'statamic.content-mcp');
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'content-mcp');

        $this->publishes([
            __DIR__ . '/../config/statamic/content-mcp.php' => config_path('statamic/content-mcp.php'),
        ], 'statamic-content-mcp-config');
    }

    public function bootAddon(): void
    {
        //
    }
}
