<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Content;

use Illuminate\Validation\ValidationException;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Bard\Augmentor;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;
use Statamic\Fieldtypes\RowId;
use Statamic\Support\Arr;
use stdClass;

/**
 * Converts field values between Statamic's stored form (what is in the YAML
 * file) and the form the model reads and writes. The two are identical except
 * for Bard: ProseMirror JSON becomes an HTML string, or a list of blocks
 * ({type: text, text: html} and {type: <set>, id, ...fields}) when the field has sets.
 *
 * HTML round trips can lose detail, so converting back keeps the stored
 * original wherever the incoming value equals the original's agent form.
 */
final class AgentForm
{
    /**
     * The values of a blueprint's fields, as `get_*` tools return them.
     *
     * @param  array<array-key, mixed>  $stored
     * @return array<array-key, mixed>|stdClass
     */
    public static function ofBlueprint(Blueprint $blueprint, array $stored): array|stdClass
    {
        $fields = $blueprint->fields();

        return self::object(self::fromStored($fields, Arr::only(Arr::removeNullValues($stored), $fields->all()->keys()->all())));
    }

    /**
     * A map for a JSON response: PHP's empty array would become `[]`, a list.
     *
     * @param  array<array-key, mixed>  $map
     * @return array<array-key, mixed>|stdClass
     */
    public static function object(array $map): array|stdClass
    {
        return $map === [] ? new stdClass : $map;
    }

    /**
     * @param  array<array-key, mixed>  $stored
     * @return array<array-key, mixed>
     */
    public static function fromStored(Fields $fields, array $stored): array
    {
        foreach ($fields->all() as $handle => $field) {
            if (array_key_exists($handle, $stored)) {
                $stored[$handle] = self::valueFromStored($field, $stored[$handle]);
            }
        }

        return $stored;
    }

    /**
     * Converts every key of $agent; $stored supplies the originals to keep.
     *
     * @param  array<array-key, mixed>  $agent
     * @param  array<array-key, mixed>  $stored
     * @return array<array-key, mixed>
     */
    public static function toStored(Fields $fields, array $agent, array $stored): array
    {
        foreach ($agent as $handle => $value) {
            $field = $fields->get($handle);

            if ($field instanceof Field) {
                $agent[$handle] = self::valueToStored($field, $value, $stored[$handle] ?? null);
            }
        }

        return $agent;
    }

    /**
     * Walks a processed value next to the processed and raw form of what was
     * stored, and returns the raw original for every part that processing alone
     * would have changed. Rows and sets are matched by id, objects by key.
     */
    public static function keepUnchanged(mixed $processed, mixed $processedOriginal, mixed $original): mixed
    {
        if (self::same($processed, $processedOriginal)) {
            return $original;
        }

        if (! is_array($processed) || ! is_array($processedOriginal) || ! is_array($original)) {
            return $processed;
        }

        if (! array_is_list($processed)) {
            foreach ($processed as $key => $value) {
                if (! array_key_exists($key, $processedOriginal)) {
                    continue;
                }

                if (array_key_exists($key, $original)) {
                    $processed[$key] = self::keepUnchanged($value, $processedOriginal[$key], $original[$key]);
                } elseif (self::same($value, $processedOriginal[$key])) {
                    // Added by processing alone, e.g. `enabled: true` or a default.
                    unset($processed[$key]);
                }
            }

            return $processed;
        }

        return array_map(function (mixed $item) use ($processedOriginal, $original): mixed {
            $id = self::rowId($item);
            $before = $id === null ? null : collect($processedOriginal)->first(fn (mixed $row): bool => self::rowId($row) === $id);
            $raw = $id === null ? null : collect($original)->first(fn (mixed $row): bool => self::rowId($row) === $id);

            return $before !== null && $raw !== null ? self::keepUnchanged($item, $before, $raw) : $item;
        }, $processed);
    }

    /**
     * Processing rebuilds groups from the blueprint, dropping keys it does not
     * know, and Field::preProcess() replaces null with the field's default. This
     * removes keys the input set to null and, unless $nullsOnly, puts back
     * unknown keys the input still has. Rows and sets are matched by id.
     */
    public static function keepInputKeys(mixed $processed, mixed $input, bool $nullsOnly = false): mixed
    {
        if ($input === null) {
            return null;
        }

        if (! is_array($processed) || ! is_array($input) || array_is_list($processed) !== array_is_list($input)) {
            return $processed;
        }

        if (array_is_list($input)) {
            return array_map(function (mixed $item) use ($input, $nullsOnly): mixed {
                $id = self::rowId($item);
                $row = $id === null ? null : collect($input)->first(fn (mixed $row): bool => self::rowId($row) === $id);

                return $row === null ? $item : self::keepInputKeys($item, $row, $nullsOnly);
            }, $processed);
        }

        foreach ($input as $key => $value) {
            if ($value === null) {
                unset($processed[$key]);
            } elseif (array_key_exists($key, $processed)) {
                $processed[$key] = self::keepInputKeys($processed[$key], $value, $nullsOnly);
            } elseif (! $nullsOnly) {
                $processed[$key] = $value;
            }
        }

        return $processed;
    }

    /**
     * The paths of read-only fields whose stored value $new changes, at any depth:
     * in groups, grid rows, replicator sets and Bard sets. $locked lists top-level
     * handles to treat as read-only too.
     *
     * @param  array<array-key, mixed>  $new
     * @param  array<array-key, mixed>  $old
     * @param  list<string>  $locked
     * @return list<string>
     */
    public static function readOnlyChanges(Fields $fields, array $new, array $old, array $locked = [], string $prefix = ''): array
    {
        $paths = [];

        foreach ($new as $handle => $value) {
            $field = $fields->get($handle);
            $original = $old[$handle] ?? null;

            if (! $field instanceof Field || self::same($value, $original)) {
                continue;
            }

            if (in_array($field->visibility(), ['read_only', 'computed'], true) || in_array($handle, $locked, true)) {
                $paths[] = $prefix.$handle;

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $fieldtype = $field->fieldtype();
            $originals = is_array($original) ? $original : [];

            array_push($paths, ...match (true) {
                $fieldtype instanceof Group => self::readOnlyChanges($fieldtype->fields(), $value, $originals, [], "{$prefix}{$handle}."),
                $fieldtype instanceof Grid => self::readOnlyRowChanges(fn (): Fields => $fieldtype->fields(), false, $value, $originals, "{$prefix}{$handle}."),
                $fieldtype instanceof Replicator => self::readOnlyRowChanges($fieldtype->fields(...), $fieldtype instanceof Bard, $value, $originals, "{$prefix}{$handle}."),
                default => [],
            });
        }

        return $paths;
    }

    /**
     * Translates a validation error key from Statamic's stored-form path
     * (`content.2.attrs.values.quote`, `page_builder.0.heading`) into the path
     * the client writes: set and row ids, or for items the client sent without
     * an id, their index in the client's list.
     *
     * @param  array<array-key, mixed>  $stored  the values that were validated
     * @param  array<array-key, mixed>  $agent  the same values as the client sent them
     */
    public static function agentPath(Fields $fields, string $path, array $stored, array $agent): string
    {
        $segments = explode('.', $path);

        return implode('.', self::agentSegments($fields, array_shift($segments), $segments, $stored, $agent));
    }

    /**
     * Stored-form paths of integer and float values the fieldtype's preProcess()
     * would cast with loss ("2abc" → 2, 2.5 → 2). Validation would only see the cast value.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, list<string>>
     */
    public static function lossyNumbers(Fields $fields, array $values, string $prefix = ''): array
    {
        $errors = [];

        foreach ($fields->all() as $handle => $field) {
            $value = $values[$handle] ?? null;
            $path = $prefix.$handle;

            if ($value === null) {
                continue;
            }

            $fieldtype = $field->fieldtype();
            $attribute = ['attribute' => $field->display()];

            if ($field->type() === 'integer' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                $errors[$path] = [__('validation.integer', $attribute)];
            } elseif (in_array($field->type(), ['float', 'range'], true) && ! is_numeric($value)) {
                $errors[$path] = [__('validation.numeric', $attribute)];
            } elseif (is_array($value) && $fieldtype instanceof Group) {
                $errors = [...$errors, ...self::lossyNumbers($fieldtype->fields(), $value, "{$path}.")];
            } elseif (is_array($value) && ($fieldtype instanceof Grid || $fieldtype instanceof Replicator)) {
                foreach ($value as $index => $row) {
                    $rowValues = $fieldtype instanceof Bard ? ($row['attrs']['values'] ?? null) : $row;
                    $rowFields = is_array($rowValues) ? self::rowFields($fieldtype, $rowValues) : null;

                    if ($rowFields !== null) {
                        $rowPrefix = $fieldtype instanceof Bard ? "{$path}.{$index}.attrs.values." : "{$path}.{$index}.";
                        $errors = [...$errors, ...self::lossyNumbers($rowFields, $rowValues, $rowPrefix)];
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Strict equality that ignores the key order of objects, since clients may reorder JSON keys.
     */
    public static function same(mixed $a, mixed $b): bool
    {
        if (! is_array($a) || ! is_array($b)) {
            return $a === $b;
        }

        if (count($a) !== count($b) || array_is_list($a) !== array_is_list($b)) {
            return false;
        }

        foreach ($a as $key => $value) {
            if (! array_key_exists($key, $b) || ! self::same($value, $b[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  callable(string): Fields  $fields
     * @param  array<array-key, mixed>  $rows
     * @param  array<array-key, mixed>  $originals
     * @return list<string>
     */
    private static function readOnlyRowChanges(callable $fields, bool $bard, array $rows, array $originals, string $prefix): array
    {
        $paths = [];

        foreach ($rows as $index => $row) {
            $id = self::rowId($row);
            $original = $id === null ? null : collect($originals)->first(fn (mixed $item): bool => self::rowId($item) === $id);
            // Bard keeps set values under attrs.values; its text nodes have no fields.
            $values = $bard ? ($row['attrs']['values'] ?? null) : $row;
            $originalValues = $bard ? ($original['attrs']['values'] ?? null) : $original;

            if (! is_array($values)) {
                continue;
            }

            $type = is_string($values['type'] ?? null) ? $values['type'] : '';
            array_push($paths, ...self::readOnlyChanges($fields($type), $values, is_array($originalValues) ? $originalValues : [], [], $prefix.($id ?? $index).'.'));
        }

        return $paths;
    }

    /**
     * @param  list<string>  $segments  the path after $handle
     * @return list<string>
     */
    private static function agentSegments(Fields $fields, string $handle, array $segments, mixed $stored, mixed $agent): array
    {
        $field = $fields->get($handle);

        if (! $field instanceof Field || $segments === []) {
            return [$handle, ...$segments];
        }

        $fieldtype = $field->fieldtype();
        $stored = is_array($stored) ? ($stored[$handle] ?? null) : null;
        $agent = is_array($agent) ? ($agent[$handle] ?? null) : null;

        if ($fieldtype instanceof Group) {
            return [$handle, ...self::agentSegments($fieldtype->fields(), array_shift($segments), $segments, $stored, $agent)];
        }

        if (! ($fieldtype instanceof Grid || $fieldtype instanceof Replicator) || ! is_array($stored) || ! ctype_digit($segments[0])) {
            return [$handle, ...$segments];
        }

        $index = (int) array_shift($segments);
        $row = array_values($stored)[$index] ?? null;
        $blocks = is_array($agent) ? $agent : [];

        if ($fieldtype instanceof Bard) {
            // The k-th set node is the k-th set block; text blocks may span several nodes.
            $isSet = fn (mixed $node): bool => is_array($node) && ($node['type'] ?? null) === 'set';
            $k = count(array_filter(array_slice(array_values($stored), 0, $index), $isSet));
            $agentIndex = array_keys(array_filter($blocks, fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) !== 'text'))[$k] ?? $index;
            $row = $row['attrs']['values'] ?? null;
            $segments = array_slice($segments, 2);
        } else {
            $agentIndex = $index;
        }

        $agentRow = $blocks[$agentIndex] ?? null;
        $id = is_array($agentRow) && is_string($agentRow[self::idKey()] ?? null) ? $agentRow[self::idKey()] : (string) $agentIndex;
        $rowFields = is_array($row) ? self::rowFields($fieldtype, $row) : null;

        if ($rowFields === null || $segments === []) {
            return [$handle, $id, ...$segments];
        }

        return [$handle, $id, ...self::agentSegments($rowFields, array_shift($segments), $segments, $row, $agentRow)];
    }

    /**
     * The fields of a grid row, or of a replicator or Bard set by its type.
     *
     * @param  array<array-key, mixed>  $values
     */
    private static function rowFields(Grid|Replicator $fieldtype, array $values): ?Fields
    {
        if ($fieldtype instanceof Grid) {
            return $fieldtype->fields();
        }

        $type = $values['type'] ?? null;

        return is_string($type) && $fieldtype->flattenedSetsConfig()->has($type) ? $fieldtype->fields($type) : null;
    }

    private static function valueFromStored(Field $field, mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $fieldtype = $field->fieldtype();

        return match (true) {
            $fieldtype instanceof Bard => self::bardFromStored($fieldtype, $value),
            $fieldtype instanceof Replicator => array_map(
                fn (mixed $set): mixed => is_array($set) && is_string($set['type'] ?? null)
                    ? self::fromStored($fieldtype->fields($set['type']), $set)
                    : $set,
                $value,
            ),
            $fieldtype instanceof Grid => array_map(
                fn (mixed $row): mixed => is_array($row) ? self::fromStored($fieldtype->fields(), $row) : $row,
                $value,
            ),
            $fieldtype instanceof Group => self::fromStored($fieldtype->fields(), $value),
            default => $value,
        };
    }

    private static function valueToStored(Field $field, mixed $value, mixed $original): mixed
    {
        if ($original !== null && self::same(self::valueFromStored($field, $original), $value)) {
            return $original;
        }

        if ($value === null) {
            return null;
        }

        $fieldtype = $field->fieldtype();
        $originals = is_array($original) ? $original : [];

        if ($fieldtype instanceof Bard) {
            return self::bardToStored($field, $fieldtype, $value, $originals);
        }

        if (! is_array($value)) {
            return $value;
        }

        return match (true) {
            $fieldtype instanceof Replicator => array_map(
                fn (mixed $set): mixed => self::rowToStored($field, $set, $originals, fn (string $type): Fields => self::setFields($field, $fieldtype, $type)),
                $value,
            ),
            $fieldtype instanceof Grid => array_map(
                fn (mixed $row): mixed => self::rowToStored($field, $row, $originals, fn (): Fields => $fieldtype->fields()),
                $value,
            ),
            $fieldtype instanceof Group => self::toStored($fieldtype->fields(), $value, $originals),
            default => $value,
        };
    }

    /**
     * A replicator set or grid row, matched to its original by id.
     *
     * @param  array<array-key, mixed>  $originals
     * @param  callable(string): Fields  $fields
     */
    private static function rowToStored(Field $field, mixed $row, array $originals, callable $fields): mixed
    {
        if (! is_array($row)) {
            throw self::invalid($field, 'Each item must be an object.');
        }

        $idKey = self::idKey();
        $type = is_string($row['type'] ?? null) ? $row['type'] : '';
        $original = self::findById($originals, $row[$idKey] ?? null);
        $rowFields = $fields($type);

        if ($original !== null && self::same(self::fromStored($rowFields, $original), $row)) {
            return $original;
        }

        return [$idKey => $row[$idKey] ?? app(RowId::class)->generate(), ...self::toStored($rowFields, $row, $original ?? [])];
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     */
    private static function bardFromStored(Bard $bard, array $nodes): mixed
    {
        if (! $bard->config('sets')) {
            return self::html($bard, $nodes);
        }

        $blocks = [];
        $run = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || ($node['type'] ?? null) !== 'set') {
                $run[] = $node;

                continue;
            }

            if ($run !== []) {
                $blocks[] = ['type' => 'text', 'text' => self::html($bard, $run)];
                $run = [];
            }

            $blocks[] = self::setBlock($bard, $node);
        }

        if ($run !== []) {
            $blocks[] = ['type' => 'text', 'text' => self::html($bard, $run)];
        }

        return $blocks;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private static function setBlock(Bard $bard, array $node): array
    {
        $values = is_array($node['attrs']['values'] ?? null) ? $node['attrs']['values'] : [];
        $type = is_string($values['type'] ?? null) ? $values['type'] : '';
        unset($values['type']);

        $block = ['type' => $type, self::idKey() => $node['attrs']['id'] ?? null];

        if (($node['attrs']['enabled'] ?? true) === false) {
            $block['enabled'] = false;
        }

        return [...$block, ...self::fromStored($bard->fields($type), $values)];
    }

    /**
     * @param  array<array-key, mixed>  $originalNodes
     */
    private static function bardToStored(Field $field, Bard $bard, mixed $value, array $originalNodes): mixed
    {
        if (! $bard->config('sets')) {
            if (! is_string($value) && $value !== null) {
                throw self::invalid($field, 'Send this field as an HTML string.');
            }

            return $value === null ? null : self::prosemirror($bard, $value);
        }

        if (is_string($value)) {
            $value = [['type' => 'text', 'text' => $value]];
        }

        if (! is_array($value)) {
            throw self::invalid($field, 'Send this field as a list of blocks.');
        }

        // Unchanged text between sets keeps its original nodes, wherever it moved.
        $originalRuns = [];
        $run = [];
        $originalSets = [];

        foreach ([...$originalNodes, null] as $node) {
            if (is_array($node) && ($node['type'] ?? null) !== 'set') {
                $run[] = $node;

                continue;
            }

            if ($run !== []) {
                $originalRuns[] = ['html' => self::html($bard, $run), 'nodes' => $run];
                $run = [];
            }

            if (is_array($node)) {
                $originalSets[] = $node;
            }
        }

        $nodes = [];

        foreach ($value as $block) {
            if (! is_array($block) || ! is_string($block['type'] ?? null)) {
                throw self::invalid($field, 'Each block needs a "type": "text" or a set handle.');
            }

            if ($block['type'] === 'text') {
                if (! is_string($block['text'] ?? null)) {
                    throw self::invalid($field, 'Text blocks need a "text" HTML string.');
                }

                $index = collect($originalRuns)->search(fn (array $run): bool => $run['html'] === $block['text']);

                if ($index !== false) {
                    array_push($nodes, ...$originalRuns[$index]['nodes']);
                    unset($originalRuns[$index]);
                } else {
                    array_push($nodes, ...self::prosemirror($bard, $block['text']));
                }

                continue;
            }

            $nodes[] = self::setNodeToStored($field, $bard, $block, $originalSets);
        }

        return $nodes;
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @param  list<array<array-key, mixed>>  $originalSets
     * @return array<array-key, mixed>
     */
    private static function setNodeToStored(Field $field, Bard $bard, array $block, array $originalSets): array
    {
        $idKey = self::idKey();
        $type = (string) $block['type'];
        $setFields = self::setFields($field, $bard, $type);
        $original = isset($block[$idKey])
            ? collect($originalSets)->first(fn (array $node): bool => ($node['attrs']['id'] ?? null) === $block[$idKey])
            : null;

        if ($original !== null && self::same(self::setBlock($bard, $original), $block)) {
            return $original;
        }

        $originalValues = $original !== null && is_array($original['attrs']['values'] ?? null) ? $original['attrs']['values'] : [];
        $values = $block;
        unset($values['type'], $values[$idKey], $values['enabled']);

        $attrs = [
            'id' => $block[$idKey] ?? app(RowId::class)->generate(),
            'values' => ['type' => $type, ...self::toStored($setFields, $values, $originalValues)],
        ];

        if (($block['enabled'] ?? true) === false) {
            $attrs['enabled'] = false;
        }

        return ['type' => 'set', 'attrs' => $attrs];
    }

    private static function setFields(Field $field, Replicator $fieldtype, string $type): Fields
    {
        if (! $fieldtype->flattenedSetsConfig()->has($type)) {
            throw self::invalid($field, "Unknown set type [{$type}]. Call get_blueprint for the allowed sets.");
        }

        return $fieldtype->fields($type);
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     */
    private static function html(Bard $bard, array $nodes): string
    {
        // withStatamicImageUrls() keeps statamic:// link and image refs instead of resolving them to URLs.
        return (new Augmentor($bard))->withStatamicImageUrls()->renderProsemirrorToHtml(['type' => 'doc', 'content' => $nodes]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function prosemirror(Bard $bard, string $html): array
    {
        // Same as Bard::preProcess(): stored image nodes reference "asset::…" without the scheme.
        $document = (new Augmentor($bard))->renderHtmlToProsemirror(str_replace('src="statamic://', 'src="', $html));

        return $document['content'] ?? [];
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<array-key, mixed>|null
     */
    private static function findById(array $rows, mixed $id): ?array
    {
        if ($id === null) {
            return null;
        }

        return collect($rows)->first(fn (mixed $row): bool => is_array($row) && ($row[self::idKey()] ?? null) === $id);
    }

    /**
     * The id of a replicator set or grid row (`_id` once preprocessed), or of a Bard set node.
     */
    private static function rowId(mixed $row): ?string
    {
        if (! is_array($row)) {
            return null;
        }

        $id = $row[self::idKey()] ?? $row['_id'] ?? (($row['type'] ?? null) === 'set' ? ($row['attrs']['id'] ?? null) : null);

        return is_string($id) ? $id : null;
    }

    private static function idKey(): string
    {
        return app(RowId::class)->handle();
    }

    private static function invalid(Field $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field->handle() => $message]);
    }
}
