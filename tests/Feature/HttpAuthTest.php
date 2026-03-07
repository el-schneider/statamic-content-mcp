<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Statamic\Facades\User;

beforeEach(function () {
    config(['statamic.content-mcp.auth' => [
        'driver' => 'static_token',
        'static_token' => [
            'token' => 'test-static-token',
            'user' => 'agent@agent.md',
        ],
    ]]);

    User::make()
        ->id((string) Str::uuid())
        ->email('agent@agent.md')
        ->password('secret')
        ->makeSuper()
        ->save();
});

it('rejects requests without a bearer token', function () {
    $this->postJson('/mcp/content', initializePayload())
        ->assertUnauthorized();
});

it('rejects requests with the wrong bearer token', function () {
    $this->withToken('wrong-token')
        ->postJson('/mcp/content', initializePayload())
        ->assertUnauthorized();
});

it('accepts requests with the configured static bearer token', function () {
    $this->withToken('test-static-token')
        ->postJson('/mcp/content', initializePayload())
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'statamic-content')
        ->assertJsonPath('result.serverInfo.version', '1.0.0');
});

it('fails fast when static token auth is misconfigured', function () {
    config(['statamic.content-mcp.auth' => [
        'driver' => 'static_token',
        'static_token' => [
            'token' => null,
            'user' => 'agent@agent.md',
        ],
    ]]);

    $this->withToken('test-static-token')
        ->postJson('/mcp/content', initializePayload())
        ->assertStatus(500);
});

it('authenticates the configured user by id', function () {
    $user = User::findByEmail('agent@agent.md');

    config(['statamic.content-mcp.auth' => [
        'driver' => 'static_token',
        'static_token' => [
            'token' => 'test-static-token',
            'user' => $user->id(),
        ],
    ]]);

    $this->withToken('test-static-token')
        ->postJson('/mcp/content', initializePayload())
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'statamic-content');
});

function initializePayload(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
            'clientInfo' => [
                'name' => 'pest',
                'version' => '1.0.0',
            ],
        ],
    ];
}
