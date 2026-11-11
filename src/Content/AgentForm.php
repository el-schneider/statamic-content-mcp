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
     * @return array<array-key, mixed>
     */
    public static function ofBlueprint(Blueprint $blueprint, array $stored): array
    {
        $fields = $blueprint->fields();

        return self::fromStored($fields, Arr::only(Arr::removeNullValues($stored), $fields->all()->keys()->all()));
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
     * Strict equality that ignores the key order of objects, since clients may reorder JSON keys.
     */
    private static function same(mixed $a, mixed $b): bool
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

    private static function idKey(): string
    {
        return app(RowId::class)->handle();
    }

    private static function invalid(Field $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field->handle() => $message]);
    }
}
