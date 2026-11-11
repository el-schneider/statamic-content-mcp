<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp;

use ElSchneider\ContentMcp\Rules\AllowedApps;
use Statamic\Facades\Addon;

/**
 * The owner's settings: the config file holds the defaults, a value saved in the
 * Control Panel (Statamic's addon settings, resources/addons/*.yaml) wins. Every
 * reader goes through here. Read per request, so config:cache freezes only the defaults.
 */
final class Settings
{
    public const string PACKAGE = 'el-schneider/statamic-content-mcp';

    public static function instructions(): ?string
    {
        $value = self::saved('instructions') ?? config('statamic.content-mcp.instructions');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * The origins OAuth clients may redirect to, in the form laravel/mcp's
     * OAuthRegisterController reads `mcp.redirect_domains`.
     *
     * @return list<string>
     */
    public static function redirectDomains(): array
    {
        if (self::redirectDomainsManagedByApp()) {
            return array_values(array_filter((array) config('mcp.redirect_domains'), is_string(...)));
        }

        $value = self::saved('redirect_domains') ?? config('statamic.content-mcp.redirect_domains');

        return array_values(array_map(
            fn (string $origin): string => $origin === '*' ? $origin : mb_strtolower(rtrim($origin, '/')),
            array_filter((array) $value, is_string(...)),
        ));
    }

    /**
     * A site that publishes config/mcp.php decides for itself. Checked by file because
     * with config:cache the cached value no longer shows who set it.
     */
    public static function redirectDomainsManagedByApp(): bool
    {
        return file_exists(config_path('mcp.php'));
    }

    public static function deletes(): bool
    {
        return (bool) (self::saved('deletes') ?? config('statamic.content-mcp.deletes'));
    }

    /**
     * The settings form. Its defaults are the config file's values, so the form shows
     * what is in effect before anything is saved, and an emptied field falls back to them.
     *
     * @return array<string, mixed>
     */
    public static function blueprint(): array
    {
        $managedByApp = self::redirectDomainsManagedByApp();

        return ['tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'instructions', 'field' => [
                'type' => 'markdown',
                'display' => __('Editorial guidelines'),
                'instructions' => __('Voice, tone, audience and conventions of your site, in Markdown. The AI assistant receives them at the start of every session. Left empty, the guidelines from the config file apply.'),
                'default' => config('statamic.content-mcp.instructions'),
            ]],
            ['handle' => 'redirect_domains', 'field' => [
                'type' => 'list',
                'display' => __('Allowed apps'),
                'instructions' => $managedByApp
                    ? __('Managed in the file config/mcp.php of your site. Changes here have no effect.')
                    : __('The addresses of the AI apps that may connect, such as https://claude.ai. Only apps on these addresses can register and send editors back after they sign in. Add http://localhost to allow desktop and command-line tools on the editor\'s own computer.'),
                'default' => $managedByApp ? config('mcp.redirect_domains') : config('statamic.content-mcp.redirect_domains'),
                ...($managedByApp ? ['visibility' => 'read_only'] : ['validate' => ['new \\'.AllowedApps::class.'()']]),
            ]],
            ['handle' => 'deletes', 'field' => [
                'type' => 'toggle',
                'display' => __('Allow deleting'),
                'instructions' => __('Lets the assistant delete entries, terms and assets for editors who may delete them. Content the assistant reads can contain text that tries to steer it, and a deletion cannot be undone.'),
                'default' => (bool) config('statamic.content-mcp.deletes'),
            ]],
        ]]]]]];
    }

    /**
     * A value saved in the Control Panel; null when never saved or left empty.
     */
    private static function saved(string $key): mixed
    {
        // raw(): only what was saved; all() adds the blueprint defaults.
        return Addon::get(self::PACKAGE)->settings()->raw()[$key] ?? null;
    }
}
