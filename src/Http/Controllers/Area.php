<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Http\Controllers;

use ElSchneider\ContentMcp\ContentMcpServiceProvider;
use ElSchneider\ContentMcp\Settings;
use Illuminate\Support\Facades\Gate;
use Statamic\Auth\User as StatamicUser;
use Statamic\Facades\Addon;
use Statamic\Facades\User;

/**
 * The Content MCP area of the Control Panel: its pages, each behind its own ability.
 */
final class Area
{
    public static function currentUserId(): string
    {
        $user = User::current();

        return $user instanceof StatamicUser ? (string) $user->getAuthIdentifier() : '';
    }

    public static function canConnect(): bool
    {
        return Gate::allows(ContentMcpServiceProvider::PERMISSION);
    }

    public static function canManage(): bool
    {
        return Gate::allows(ContentMcpServiceProvider::MANAGE_PERMISSION);
    }

    public static function canEditSettings(): bool
    {
        return Gate::allows('editSettings', Addon::get(Settings::PACKAGE));
    }

    /**
     * The sub-navigation of a page, with only the pages the user may open.
     *
     * @return list<array{title: string, url: string, active: bool}>
     */
    public static function tabs(string $current): array
    {
        $pages = [
            'connect' => [__('Connect'), self::canConnect()],
            'connections' => [__('Connections'), self::canManage()],
            'settings' => [__('Settings'), self::canEditSettings()],
        ];

        $tabs = [];

        foreach ($pages as $page => [$title, $allowed]) {
            if ($allowed) {
                $tabs[] = ['title' => $title, 'url' => cp_route("content-mcp.{$page}"), 'active' => $page === $current];
            }
        }

        return $tabs;
    }
}
