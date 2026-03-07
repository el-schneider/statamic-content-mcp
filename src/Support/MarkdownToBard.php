<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Support;

use Illuminate\Support\Str;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Bard\Augmentor;

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
     * Convert a plain markdown string to Bard ProseMirror nodes.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fromMarkdown(string $markdown): array
    {
        if (mb_trim($markdown) === '') {
            return [];
        }

        $html = \Statamic\Support\Str::markdown($markdown);

        return self::htmlToProsemirrorNodes($html);
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
                $html = \Statamic\Support\Str::markdown($block['text'] ?? '');
                if (mb_trim($html) !== '') {
                    $result = [...$result, ...self::htmlToProsemirrorNodes($html)];
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

    /**
     * Convert HTML to ProseMirror nodes using Statamic's Bard Augmentor.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function htmlToProsemirrorNodes(string $html): array
    {
        $augmentor = new Augmentor(new Bard);
        $doc = $augmentor->renderHtmlToProsemirror($html);

        return $doc['content'] ?? [];
    }
}
