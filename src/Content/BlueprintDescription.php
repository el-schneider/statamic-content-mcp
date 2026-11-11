<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Content;

use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;
use Statamic\Support\Arr;

/**
 * A compact view of Statamic's own field config for a model that writes
 * stored values: what each field accepts, without presentation settings.
 */
final class BlueprintDescription
{
    private const array OMITTED_CONFIG = [
        'type', 'display', 'instructions', 'required', 'validate', 'visibility', 'read_only', 'sets', 'fields',
        'width', 'icon', 'listable', 'hide_display', 'instructions_position', 'localizable', 'always_save',
        'antlers', 'placeholder', 'reserve_space_when_hidden', 'duplicate', 'sortable', 'filterable',
        'if', 'unless', 'if_any', 'unless_any', 'show_when', 'hide_when', 'replicator_preview', 'collapse',
        'previews', 'fullscreen', 'toolbar_mode', 'reading_time', 'word_count', 'prepend', 'append',
        'buttons', 'smart_typography', 'enable_input_rules', 'enable_paste_rules', 'link_noopener',
        'link_noreferrer', 'target_blank', 'always_show_set_button', 'inline_label', 'inline_label_when_true',
        'show_set_label', 'show_html_source', 'fixed_toolbar', 'mode', 'listable_columns', 'set_ignore',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public static function fields(Fields $fields): array
    {
        return array_values($fields->all()->map(self::field(...))->all());
    }

    /**
     * @return array<string, mixed>
     */
    private static function field(Field $field): array
    {
        $fieldtype = $field->fieldtype();
        $validate = array_values(array_diff(Arr::wrap($field->get('validate')), ['required']));

        $description = array_filter([
            'handle' => $field->handle(),
            'type' => $field->type(),
            'display' => $field->display(),
            'instructions' => $field->instructions(),
            'required' => $field->isRequired(),
            'validate' => $validate,
            'read_only' => in_array($field->visibility(), ['read_only', 'computed'], true),
            'value' => self::valueHint($field),
            ...Arr::except($field->config(), self::OMITTED_CONFIG),
        ], fn (mixed $value): bool => $value !== null && $value !== false && $value !== [] && $value !== '');

        return match (true) {
            $fieldtype instanceof Bard => $field->get('sets')
                ? [...$description, 'format' => 'blocks', 'sets' => self::sets($fieldtype)]
                : [...$description, 'format' => 'html'],
            $fieldtype instanceof Replicator => [...$description, 'sets' => self::sets($fieldtype)],
            $fieldtype instanceof Grid, $fieldtype instanceof Group => [...$description, 'fields' => self::fields($fieldtype->fields())],
            default => $description,
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function sets(Replicator $fieldtype): array
    {
        return $fieldtype->flattenedSetsConfig()
            ->map(fn (array $set, string $handle): array => array_filter([
                'display' => $set['display'] ?? null,
                'instructions' => $set['instructions'] ?? null,
                'fields' => self::fields($fieldtype->fields($handle)),
            ]))
            ->all();
    }

    private static function valueHint(Field $field): ?string
    {
        $single = (int) ($field->get('max_items') ?? $field->get('max_files')) === 1;

        return match ($field->type()) {
            'bard' => $field->get('sets')
                ? 'List of blocks: {"type": "text", "text": "<p>HTML</p>"} and {"type": "<set handle>", "id": "…", …set fields}. Omit id for new sets. Link entries with href="statamic://entry::<id>".'
                : 'HTML string. Link entries with href="statamic://entry::<id>".',
            'replicator' => 'List of sets: {"type": "<set handle>", "id": "…", …set fields}. Omit id for new sets.',
            'grid' => 'List of rows: {"id": "…", …fields}. Omit id for new rows.',
            'group' => 'Object with the nested fields.',
            'entries' => $single ? 'One entry id.' : 'List of entry ids.',
            'assets' => $single ? 'One asset path, relative to the container.' : 'List of asset paths, relative to the container.',
            'terms' => $single ? 'One term slug.' : 'List of term slugs.',
            'users' => $single ? 'One user id.' : 'List of user ids.',
            'date' => $field->get('mode') === 'range'
                ? 'Object {"start": "2025-01-31", "end": "2025-02-02"}.'
                : 'Date string, e.g. "2025-01-31" or "2025-01-31 14:30".',
            'toggle' => 'true or false.',
            'select', 'button_group' => $field->get('multiple') ? 'List of option keys.' : 'One option key.',
            'radio' => 'One option key.',
            'checkboxes' => 'List of option keys.',
            'link' => 'A URL, or entry::<id> to link an entry.',
            'markdown' => 'Markdown string.',
            default => null,
        };
    }
}
