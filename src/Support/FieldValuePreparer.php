<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Support;

use Illuminate\Support\Str;
use Statamic\Fields\Blueprint;

final class FieldValuePreparer
{
    /**
     * Prepare AI-submitted field values for storage.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepare(array $data, Blueprint $blueprint): array
    {
        $prepared = [];

        foreach ($data as $handle => $value) {
            $field = $blueprint->field($handle);

            if (! $field) {
                $prepared[$handle] = $value;

                continue;
            }

            $prepared[$handle] = self::prepareValue($value, $field->type(), $field->config());
        }

        return $prepared;
    }

    private static function prepareValue(mixed $value, string $type, array $config): mixed
    {
        return match ($type) {
            'bard' => MarkdownToBard::convert($value),
            'assets' => self::prepareAssets($value),
            'replicator' => self::prepareReplicator($value, $config),
            default => $value,
        };
    }

    private static function prepareAssets(mixed $value): mixed
    {
        if (is_string($value)) {
            return [$value];
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function prepareReplicator(mixed $value, array $config): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $setFieldTypes = self::resolveSetFields($config['sets'] ?? []);

        return array_map(function (array $set) use ($setFieldTypes) {
            $type = $set['type'] ?? null;

            if (! isset($set['id'])) {
                $set['id'] = 'set-'.Str::random(8);
            }

            if (! $type || ! isset($setFieldTypes[$type])) {
                return $set;
            }

            foreach ($setFieldTypes[$type] as $handle => $fieldType) {
                if (isset($set[$handle])) {
                    $set[$handle] = self::prepareValue($set[$handle], $fieldType, []);
                }
            }

            return $set;
        }, $value);
    }

    /**
     * @return array<string, array<string, string>> [setHandle => [fieldHandle => fieldType]]
     */
    private static function resolveSetFields(array $sets): array
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
