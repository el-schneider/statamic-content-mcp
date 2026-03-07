<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Support;

use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Fields\Field;

final class EntryFormatter
{
    /** @return array<string, mixed> */
    public static function forList(Entry $entry): array
    {
        $data = [
            'id' => $entry->id(),
            'title' => $entry->get('title'),
            'slug' => $entry->slug(),
            'status' => self::status($entry),
        ];

        if ($entry->collection()->dated()) {
            $data['date'] = $entry->date()?->toDateString();
        }

        $data['url'] = $entry->url();

        return $data;
    }

    /** @return array<string, mixed> */
    public static function forGet(Entry $entry): array
    {
        $blueprint = $entry->blueprint();

        $fieldValues = [];
        foreach ($blueprint->fields()->all() as $field) {
            if ($field->type() === 'section') {
                continue;
            }

            $value = $entry->get($field->handle());
            if ($value === null) {
                continue;
            }

            $fieldValues[$field->handle()] = self::formatValue($value, $field);
        }

        $result = [
            'id' => $entry->id(),
            'collection' => $entry->collectionHandle(),
            'blueprint' => $blueprint->handle(),
            'slug' => $entry->slug(),
            'status' => self::status($entry),
            'url' => $entry->url(),
            'edit_url' => '/cp/collections/'.$entry->collectionHandle().'/entries/'.$entry->id(),
        ];

        if ($entry->collection()->dated()) {
            $result['date'] = $entry->date()?->toDateString();
        }

        $result['data'] = $fieldValues;

        return $result;
    }

    private static function status(Entry $entry): string
    {
        if (! $entry->published()) {
            return 'draft';
        }

        if ($entry->collection()->dated() && $entry->date()?->isFuture()) {
            return 'scheduled';
        }

        return 'published';
    }

    private static function formatValue(mixed $value, Field $field): mixed
    {
        return match ($field->type()) {
            'bard' => BardToMarkdown::convert($value),
            'assets' => self::formatAssets($value, $field),
            'terms' => self::formatTerms($value, $field),
            'users' => self::formatUsers($value),
            'entries' => self::formatRelatedEntries($value),
            'replicator' => self::formatReplicator($value, $field),
            default => $value,
        };
    }

    private static function formatAssets(mixed $value, Field $field): mixed
    {
        $container = $field->config()['container'] ?? 'assets';
        $maxFiles = $field->config()['max_files'] ?? null;
        $values = is_array($value) ? $value : [$value];

        $formatted = [];
        foreach ($values as $path) {
            $id = str_contains((string) $path, '::') ? $path : $container.'::'.$path;
            $asset = Asset::find($id);

            $formatted[] = [
                'id' => $id,
                'url' => $asset?->url(),
            ];
        }

        return $maxFiles === 1 ? ($formatted[0] ?? null) : $formatted;
    }

    /** @return array<int, array<string, mixed>> */
    private static function formatTerms(mixed $value, Field $field): array
    {
        if (! is_array($value)) {
            $value = [$value];
        }

        $taxonomies = $field->config()['taxonomies'] ?? [];
        if (is_string($taxonomies)) {
            $taxonomies = [$taxonomies];
        }

        $formatted = [];
        foreach ($value as $slug) {
            $term = null;
            foreach ($taxonomies as $taxonomy) {
                $term = Term::find($taxonomy.'::'.$slug);
                if ($term) {
                    break;
                }
            }

            $formatted[] = [
                'slug' => $slug,
                'title' => $term?->title() ?? $slug,
            ];
        }

        return $formatted;
    }

    private static function formatUsers(mixed $value): mixed
    {
        $values = is_array($value) ? $value : [$value];
        $formatted = [];

        foreach ($values as $id) {
            $user = User::find($id);
            $formatted[] = [
                'id' => $id,
                'name' => $user?->name(),
                'email' => $user?->email(),
            ];
        }

        return count($values) === 1 ? $formatted[0] : $formatted;
    }

    private static function formatRelatedEntries(mixed $value): mixed
    {
        $values = is_array($value) ? $value : [$value];
        $formatted = [];

        foreach ($values as $id) {
            $entry = EntryFacade::find($id);
            $formatted[] = [
                'id' => $id,
                'title' => $entry?->get('title'),
            ];
        }

        return count($values) === 1 ? $formatted[0] : $formatted;
    }

    private static function formatReplicator(mixed $value, Field $field): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $setFieldTypes = self::resolveSetFieldTypes($field->config()['sets'] ?? []);

        return array_map(function (array $set) use ($setFieldTypes) {
            $type = $set['type'] ?? null;
            if (! $type || ! isset($setFieldTypes[$type])) {
                return $set;
            }

            $formatted = ['type' => $type];
            foreach ($setFieldTypes[$type] as $handle => $fieldType) {
                if (array_key_exists($handle, $set)) {
                    $formatted[$handle] = match ($fieldType) {
                        'bard' => BardToMarkdown::convert($set[$handle]),
                        default => $set[$handle],
                    };
                }
            }

            return $formatted;
        }, $value);
    }

    /**
     * @return array<string, array<string, string>> [setHandle => [fieldHandle => fieldType]]
     */
    private static function resolveSetFieldTypes(array $sets): array
    {
        $first = reset($sets);
        if ($first && isset($first['sets'])) {
            $flattened = [];
            foreach ($sets as $group) {
                foreach ($group['sets'] ?? [] as $handle => $setConfig) {
                    $flattened[$handle] = $setConfig;
                }
            }
            $sets = $flattened;
        }

        $result = [];
        foreach ($sets as $handle => $setConfig) {
            $fields = [];
            foreach ($setConfig['fields'] ?? [] as $fieldDef) {
                $fields[$fieldDef['handle']] = $fieldDef['field']['type'] ?? 'text';
            }
            $result[$handle] = $fields;
        }

        return $result;
    }
}
