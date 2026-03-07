<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Support;

use Illuminate\Support\Str;

final class MarkdownToBard
{
    /**
     * Convert markdown (string or JSON array with sets) to Bard storage format.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function convert(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_string($value)) {
            return self::fromMarkdown($value);
        }

        if (is_array($value)) {
            return self::fromBlockArray($value);
        }

        return null;
    }

    /**
     * Convert a plain markdown string to Bard storage format.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fromMarkdown(string $markdown): array
    {
        if (mb_trim($markdown) === '') {
            return [];
        }

        $html = self::markdownToHtml($markdown);

        return [['type' => 'text', 'text' => $html]];
    }

    /**
     * Convert a mixed array of text blocks and set blocks to Bard storage format.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private static function fromBlockArray(array $blocks): array
    {
        $result = [];

        foreach ($blocks as $block) {
            $type = $block['type'] ?? null;

            if ($type === 'text') {
                $html = self::markdownToHtml($block['text'] ?? '');
                if ($html !== '') {
                    $result[] = ['type' => 'text', 'text' => $html];
                }
            } else {
                $values = $block;
                $id = $values['id'] ?? 'set-'.Str::random(8);
                unset($values['id']);

                $result[] = [
                    'type' => 'set',
                    'attrs' => [
                        'id' => $id,
                        'values' => $values,
                    ],
                ];
            }
        }

        return $result;
    }

    private static function markdownToHtml(string $markdown): string
    {
        if (mb_trim($markdown) === '') {
            return '';
        }

        return \Statamic\Support\Str::markdown($markdown);
    }
}
