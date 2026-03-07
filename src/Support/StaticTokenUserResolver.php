<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Statamic\Facades\User;

final class StaticTokenUserResolver
{
    public function resolve(?string $identifier): ?Authenticatable
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
