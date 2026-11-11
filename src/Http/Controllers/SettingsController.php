<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Http\Controllers;

use ElSchneider\ContentMcp\Settings;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Facades\Addon;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Statamic's own addon settings form, inside the Content MCP area. It saves through
 * Statamic's settings route, so this page and the stock one under Addons cannot disagree.
 */
final class SettingsController extends CpController
{
    public function __invoke(): Response
    {
        $addon = Addon::get(Settings::PACKAGE);
        $this->authorize('editSettings', $addon);

        $blueprint = $addon->settingsBlueprint();
        $fields = $blueprint->fields()->addValues($addon->settings()->raw())->preProcess();

        return Inertia::render('content-mcp::Settings', [
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values(),
            'meta' => $fields->meta(),
            'submitUrl' => cp_route('addons.settings.update', $addon->slug()),
            'tabs' => Area::tabs('settings'),
        ]);
    }
}
