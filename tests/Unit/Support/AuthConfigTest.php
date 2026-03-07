<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Support\AuthConfig;

it('supports the legacy scalar auth config', function () {
    config(['statamic.content-mcp.auth' => 'sanctum']);

    $config = app(AuthConfig::class);

    expect($config->driver())->toBe('sanctum')
        ->and($config->staticToken())->toBeNull()
        ->and($config->staticTokenUser())->toBeNull();
});

it('reads the structured auth config', function () {
    config(['statamic.content-mcp.auth' => [
        'driver' => 'static_token',
        'static_token' => [
            'token' => 'secret-token',
            'user' => 'agent@agent.md',
        ],
    ]]);

    $config = app(AuthConfig::class);

    expect($config->driver())->toBe('static_token')
        ->and($config->staticToken())->toBe('secret-token')
        ->and($config->staticTokenUser())->toBe('agent@agent.md');
});

it('casts numeric static token users to strings', function () {
    config(['statamic.content-mcp.auth' => [
        'driver' => 'static_token',
        'static_token' => [
            'token' => 'secret-token',
            'user' => 1,
        ],
    ]]);

    $config = app(AuthConfig::class);

    expect($config->staticTokenUser())->toBe('1');
});
