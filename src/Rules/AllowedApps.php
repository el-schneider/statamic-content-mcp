<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Entries of the "Allowed apps" setting. laravel/mcp's OAuthRegisterController matches a
 * redirect URI by prefix against "<entry>/" and treats "*" as any app, so an entry must be
 * a scheme and host (and port), nothing more.
 */
final class AllowedApps implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach ((array) $value as $entry) {
            if (! is_string($entry) || ! self::valid($entry)) {
                $fail(__(':value is not an app address. Enter only the scheme and host, such as https://claude.ai or http://localhost.', ['value' => is_scalar($entry) ? (string) $entry : '']));

                return;
            }
        }
    }

    private static function valid(string $entry): bool
    {
        if ($entry === '*') {
            return true;
        }

        $parts = parse_url(rtrim($entry, '/'));

        return is_array($parts)
            && in_array(mb_strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && preg_match('/^[a-z0-9.-]+$|^\[[0-9a-f:]+\]$/i', $parts['host'] ?? '') === 1
            && array_diff(array_keys($parts), ['scheme', 'host', 'port']) === [];
    }
}
