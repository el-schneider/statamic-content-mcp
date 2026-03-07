<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Support;

final class AuthConfig
{
    public function driver(): string
    {
        $auth = config('statamic.content-mcp.auth', 'sanctum');

        if (is_string($auth)) {
            return $auth;
        }

        return $auth['driver'] ?? 'sanctum';
    }

    public function staticToken(): ?string
    {
        $token = $this->configValue('static_token', 'token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function staticTokenUser(): ?string
    {
        $user = $this->configValue('static_token', 'user');

        if (is_string($user) && $user !== '') {
            return $user;
        }

        if (is_int($user)) {
            return (string) $user;
        }

        return null;
    }

    private function configValue(string $section, string $key): mixed
    {
        $auth = config('statamic.content-mcp.auth', []);

        if (! is_array($auth)) {
            return null;
        }

        $sectionConfig = $auth[$section] ?? null;

        if (! is_array($sectionConfig)) {
            return null;
        }

        return $sectionConfig[$key] ?? null;
    }
}
