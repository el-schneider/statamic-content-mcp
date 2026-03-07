<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Support\StaticTokenUserResolver;
use Illuminate\Support\Str;
use Statamic\Facades\User;

it('resolves a user by email', function () {
    $user = User::make()
        ->id((string) Str::uuid())
        ->email('agent@agent.md')
        ->password('secret')
        ->makeSuper();

    $user->save();

    $resolved = app(StaticTokenUserResolver::class)->resolve('agent@agent.md');

    expect($resolved?->getAuthIdentifier())->toBe($user->id());
});

it('resolves a user by id', function () {
    $user = User::make()
        ->id((string) Str::uuid())
        ->email('agent@agent.md')
        ->password('secret')
        ->makeSuper();

    $user->save();

    $resolved = app(StaticTokenUserResolver::class)->resolve($user->id());

    expect($resolved?->getAuthIdentifier())->toBe($user->id());
});

it('returns null for an unknown user', function () {
    expect(app(StaticTokenUserResolver::class)->resolve('missing@agent.md'))->toBeNull();
});
