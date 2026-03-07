<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Support;

use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

final class BlueprintDescriber
{
    /** @return array<int, array<string, mixed>> */
    public static function describe(Blueprint $blueprint): array
    {
        return $blueprint->fields()->all()
            ->filter(fn (Field $field) => $field->type() !== 'section')
            ->map(fn (Field $field) => self::describeFromConfig(
                $field->handle(),
                $field->display(),
                $field->type(),
                $field->config()
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function describeFromConfig(string $handle, string $display, string $type, array $config): array
    {
        $result = [
            'handle' => $handle,
            'display' => $display,
            'type' => $type,
        ];

        if (self::isRequired($config)) {
            $result['required'] = true;
        }

        if ($instructions = $config['instructions'] ?? null) {
            $result['instructions'] = $instructions;
        }

        return match ($type) {
            'text', 'textarea' => self::withCharacterLimit($result, $config),
            'select', 'radio', 'checkboxes', 'button_group' => self::withOptions($result, $config),
            'assets' => self::withAssetProps($result, $config),
            'terms' => self::withTermProps($result, $config),
            'users' => self::withMaxItems($result, $config),
            'entries' => self::withEntryProps($result, $config),
            'bard' => self::withBardProps($result, $config),
            'replicator' => self::withReplicatorProps($result, $config),
            'grid' => self::withGridProps($result, $config),
            'integer', 'float' => self::withNumericRange($result, $config),
            'template' => self::withTemplateProps($result, $config),
            default => $result,
        };
    }

    /** @param array<string, mixed> $config */
    private static function isRequired(array $config): bool
    {
        if ($config['required'] ?? false) {
            return true;
        }

        $validate = $config['validate'] ?? [];

        if (is_string($validate)) {
            $validate = explode('|', $validate);
        }

        return in_array('required', (array) $validate, strict: true);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function withCharacterLimit(array $result, array $config): array
    {
        if ($limit = $config['character_limit'] ?? null) {
            $result['character_limit'] = $limit;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function withOptions(array $result, array $config): array
    {
        if ($options = $config['options'] ?? null) {
            $result['options'] = $options;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function withAssetProps(array $result, array $config): array
    {
        if ($container = $config['container'] ?? null) {
            $result['container'] = $container;
        }

        if ($maxFiles = $config['max_files'] ?? null) {
            $result['max_files'] = $maxFiles;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function withTermProps(array $result, array $config): array
    {
        if ($taxonomies = $config['taxonomies'] ?? null) {
            $result['taxonomies'] = $taxonomies;
        }

        if (array_key_exists('create', $config)) {
            $result['create'] = $config['create'];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function withMaxItems(array $result, array $config): array
    {
        if ($maxItems = $config['max_items'] ?? null) {
            $result['max_items'] = $maxItems;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function withEntryProps(array $result, array $config): array
    {
        if ($collections = $config['collections'] ?? null) {
            $result['collections'] = $collections;
        }

        if ($maxItems = $config['max_items'] ?? null) {
            $result['max_items'] = $maxItems;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function withBardProps(array $result, array $config): array
    {
        $result['accepts'] = 'markdown';

        $sets = self::describeSets($config['sets'] ?? []);

        if ($sets !== []) {
            $result['sets'] = $sets;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function withReplicatorProps(array $result, array $config): array
    {
        $sets = self::describeSets($config['sets'] ?? []);

        if ($sets !== []) {
            $result['sets'] = $sets;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function withGridProps(array $result, array $config): array
    {
        $result['fields'] = self::describeRawFields($config['fields'] ?? []);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function withNumericRange(array $result, array $config): array
    {
        if (isset($config['min'])) {
            $result['min'] = $config['min'];
        }

        if (isset($config['max'])) {
            $result['max'] = $config['max'];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function withTemplateProps(array $result, array $config): array
    {
        if (isset($config['hide_partials'])) {
            $result['hide_partials'] = $config['hide_partials'];
        }

        return $result;
    }

    /**
     * Describe Bard/Replicator sets. Handles both flat and grouped formats.
     *
     * Flat:    { set_handle: { display: ..., fields: [...] } }
     * Grouped: { group_handle: { sets: { set_handle: { display: ..., fields: [...] } } } }
     *
     * @param  array<string, mixed>  $sets
     * @return array<int, array<string, mixed>>
     */
    private static function describeSets(array $sets): array
    {
        if ($sets === []) {
            return [];
        }

        $first = reset($sets);

        if (isset($first['sets'])) {
            $flattened = [];

            foreach ($sets as $group) {
                foreach ($group['sets'] ?? [] as $handle => $setConfig) {
                    $flattened[$handle] = $setConfig;
                }
            }

            $sets = $flattened;
        }

        return collect($sets)
            ->map(fn ($setConfig, $handle) => [
                'handle' => $handle,
                'display' => $setConfig['display'] ?? $handle,
                'fields' => self::describeRawFields($setConfig['fields'] ?? []),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawFields
     * @return array<int, array<string, mixed>>
     */
    private static function describeRawFields(array $rawFields): array
    {
        return collect($rawFields)
            ->filter(fn ($raw) => ($raw['field']['type'] ?? null) !== 'section')
            ->map(fn ($raw) => self::describeFromConfig(
                $raw['handle'],
                $raw['field']['display'] ?? $raw['handle'],
                $raw['field']['type'] ?? 'text',
                $raw['field']
            ))
            ->values()
            ->all();
    }
}
