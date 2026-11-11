<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp;

use Statamic\Addons\FileSettings;

/**
 * Statamic's file-based addon settings, with our values kept as written. Statamic's
 * Settings parses every saved value and default as Antlers when it is created, which
 * fails on guidelines that show template code such as `{{ 1 / 0 }}`.
 */
final class LiteralSettings extends FileSettings
{
    protected function resolveAntlersValue(mixed $value): mixed
    {
        return $value;
    }
}
