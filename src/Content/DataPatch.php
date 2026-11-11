<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Content;

use Illuminate\Validation\ValidationException;
use Statamic\Fieldtypes\RowId;

/**
 * Applies `data` from update tools to agent-form values. A plain key replaces
 * the field. A dot path patches one nested value: each segment is an array key,
 * or inside a list of sets, the set whose id equals the segment.
 * Example: {"page_builder.lx3k2a.text": "<p>…</p>"}.
 */
final class DataPatch
{
    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, mixed>  $changes
     * @return array<array-key, mixed>
     */
    public static function apply(array $values, array $changes): array
    {
        foreach ($changes as $path => $value) {
            $values = self::set($values, explode('.', (string) $path), $value, (string) $path);
        }

        return $values;
    }

    /**
     * The top-level field handles that $changes touch.
     *
     * @param  array<array-key, mixed>  $changes
     * @return list<string>
     */
    public static function handles(array $changes): array
    {
        return array_values(array_unique(array_map(
            fn (int|string $path): string => explode('.', (string) $path)[0],
            array_keys($changes),
        )));
    }

    /**
     * @param  list<string>  $segments
     */
    private static function set(mixed $node, array $segments, mixed $value, string $path): mixed
    {
        if ($segments === []) {
            return $value;
        }

        $node ??= [];

        if (! is_array($node)) {
            throw ValidationException::withMessages([$path => 'This path points into a value that has no nested keys.']);
        }

        $segment = array_shift($segments);
        $key = $node !== [] && array_is_list($node) ? self::listKey($node, $segment, $path) : $segment;
        $node[$key] = self::set($node[$key] ?? null, $segments, $value, $path);

        return $node;
    }

    /**
     * @param  list<mixed>  $list
     */
    private static function listKey(array $list, string $segment, string $path): int
    {
        $idKey = app(RowId::class)->handle();

        foreach ($list as $index => $item) {
            if (is_array($item) && ($item[$idKey] ?? null) === $segment) {
                return $index;
            }
        }

        if (ctype_digit($segment) && array_key_exists((int) $segment, $list)) {
            return (int) $segment;
        }

        throw ValidationException::withMessages([$path => "No item with id [{$segment}] in this list. Send the whole field to add items."]);
    }
}
