<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Support;

use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Facades\User;

final class StaticTokenUserResolver
{
    public function resolve(?string $identifier): ?StatamicUser
    {
        if (! is_string($identifier) || $identifier === '') {
            return null;
        }

        if (str_contains($identifier, '@')) {
            return User::findByEmail($identifier);
        }

        return User::find($identifier) ?? User::findByEmail($identifier);
    }
}
