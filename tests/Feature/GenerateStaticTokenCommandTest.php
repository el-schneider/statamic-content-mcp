<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Statamic\Facades\User;

beforeEach(function () {
    User::make()
        ->id((string) Str::uuid())
        ->email('agent@agent.md')
        ->password('secret')
        ->makeSuper()
        ->save();
});

it('outputs env lines and a generated token by default', function () {
    $exitCode = Artisan::call('content-mcp:generate-static-token', [
        '--user' => 'agent@agent.md',
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('STATAMIC_CONTENT_MCP_AUTH_DRIVER=static_token')
        ->and($output)->toContain('STATAMIC_CONTENT_MCP_STATIC_TOKEN_USER=agent@agent.md');

    preg_match('/STATAMIC_CONTENT_MCP_STATIC_TOKEN=([A-Za-z0-9]+)/', $output, $matches);

    expect($matches[1] ?? null)->not->toBeNull()
        ->and(mb_strlen($matches[1]))->toBe(64);
});

it('outputs only the raw token when requested', function () {
    $exitCode = Artisan::call('content-mcp:generate-static-token', [
        '--raw' => true,
        '--user' => 'agent@agent.md',
        '--length' => 48,
    ]);

    $output = mb_trim(Artisan::output());

    expect($exitCode)->toBe(0)
        ->and(mb_strlen($output))->toBe(48)
        ->and($output)->toMatch('/^[A-Za-z0-9]+$/');
});

it('preserves the provided user id in the env output', function () {
    $user = User::findByEmail('agent@agent.md');

    $exitCode = Artisan::call('content-mcp:generate-static-token', [
        '--user' => $user->id(),
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('STATAMIC_CONTENT_MCP_STATIC_TOKEN_USER='.$user->id());
});

it('fails when the user option is omitted', function () {
    $exitCode = Artisan::call('content-mcp:generate-static-token');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('The --user option is required and must be a Statamic user email or id.');
});

it('fails when the configured user does not exist', function () {
    $exitCode = Artisan::call('content-mcp:generate-static-token', [
        '--user' => 'missing@agent.md',
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Could not find Statamic user [missing@agent.md].');
});

it('fails when the length is outside the allowed range', function () {
    $tooShort = Artisan::call('content-mcp:generate-static-token', [
        '--user' => 'agent@agent.md',
        '--length' => 12,
    ]);

    expect($tooShort)->toBe(1)
        ->and(Artisan::output())->toContain('The --length option must be an integer between 32 and 128.');

    $tooLong = Artisan::call('content-mcp:generate-static-token', [
        '--user' => 'agent@agent.md',
        '--length' => 129,
    ]);

    expect($tooLong)->toBe(1)
        ->and(Artisan::output())->toContain('The --length option must be an integer between 32 and 128.');

    $notInteger = Artisan::call('content-mcp:generate-static-token', [
        '--user' => 'agent@agent.md',
        '--length' => 'abc',
    ]);

    expect($notInteger)->toBe(1)
        ->and(Artisan::output())->toContain('The --length option must be an integer between 32 and 128.');
});
