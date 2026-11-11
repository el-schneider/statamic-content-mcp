<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp;

use Statamic\Addons\FileSettings;

/**
 * Our settings in Statamic's file storage (resources/addons/*.yaml), with two changes:
 * values stay as written, and only what the owner changed is stored.
 */
final class SettingsFile extends FileSettings
{
    /**
     * The form submits every field. A value equal to the config file's is not stored, so
     * it keeps following the config file when a developer changes it. Neither is an empty
     * one, which means "use the config file", except an empty list: no app may register.
     *
     * @return array<string, mixed>
     */
    public function fileData(): array
    {
        $defaults = Settings::defaults();

        return array_filter(
            $this->raw(),
            fn (mixed $value, string $key): bool => ! in_array($value, [null, ''], true) && self::comparable($value) !== self::comparable($defaults[$key] ?? null),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Statamic's Settings parses every saved value and default as Antlers when it is
     * created, which fails on guidelines that show template code such as `{{ 1 / 0 }}`.
     */
    protected function resolveAntlersValue(mixed $value): mixed
    {
        return $value;
    }

    // Statamic's file storage would drop the empty list, too.
    protected function shouldRemoveNullsFromFileData(): bool
    {
        return false;
    }

    /**
     * A config value read from a file may end in a newline or use CRLF, and may be a list
     * with gaps in its keys; the form posts it trimmed, with LF, reindexed.
     */
    private static function comparable(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim(str_replace(["\r\n", "\r"], "\n", $value));
        }

        if (is_array($value)) {
            $items = array_map(self::comparable(...), array_values($value));
            sort($items);

            return $items;
        }

        return $value;
    }
}
