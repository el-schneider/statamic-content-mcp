<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Bard\Augmentor;

final class BardToMarkdown
{
    /**
     * Convert a Bard field value to markdown (no sets) or a mixed array (with sets).
     *
     * @return string|array<int, array<string, mixed>>
     */
    public static function convert(mixed $value): string|array
    {
        if (! is_array($value) || $value === []) {
            return '';
        }

        $hasSets = false;
        foreach ($value as $node) {
            if (($node['type'] ?? null) === 'set') {
                $hasSets = true;
                break;
            }
        }

        if (! $hasSets) {
            return self::nodesToMarkdown($value);
        }

        return self::convertWithSets($value);
    }

    /** @param array<int, array<string, mixed>> $nodes */
    private static function nodesToMarkdown(array $nodes): string
    {
        $html = self::prosemirrorNodesToHtml($nodes);

        return $html !== '' ? self::htmlToMarkdown($html) : '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private static function convertWithSets(array $nodes): array
    {
        $result = [];
        $textBuffer = [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'set') {
                if ($textBuffer !== []) {
                    $md = self::nodesToMarkdown($textBuffer);
                    if ($md !== '') {
                        $result[] = ['type' => 'text', 'text' => $md];
                    }
                    $textBuffer = [];
                }
                $result[] = $node['attrs']['values'] ?? [];
            } else {
                $textBuffer[] = $node;
            }
        }

        if ($textBuffer !== []) {
            $md = self::nodesToMarkdown($textBuffer);
            if ($md !== '') {
                $result[] = ['type' => 'text', 'text' => $md];
            }
        }

        return $result;
    }

    /**
     * Convert ProseMirror nodes to HTML using Statamic's Bard Augmentor.
     *
     * Also handles legacy format where nodes are {type: "text", text: "<html>"}.
     */
    private static function prosemirrorNodesToHtml(array $nodes): string
    {
        // Handle legacy format: {type: "text", text: "<html>"}
        if (self::isLegacyFormat($nodes)) {
            return implode('', array_map(
                fn ($node) => $node['text'] ?? '',
                $nodes,
            ));
        }

        $augmentor = new Augmentor(new Bard);

        return $augmentor->renderProsemirrorToHtml([
            'type' => 'doc',
            'content' => $nodes,
        ]);
    }

    /**
     * Detect legacy Bard format where text nodes contain HTML strings.
     *
     * Legacy: [{type: "text", text: "<p>...</p>"}]
     * Modern: [{type: "paragraph", content: [{type: "text", text: "..."}]}]
     */
    private static function isLegacyFormat(array $nodes): bool
    {
        foreach ($nodes as $node) {
            $type = $node['type'] ?? null;
            if ($type === 'text' && isset($node['text']) && is_string($node['text']) && str_contains($node['text'], '<')) {
                return true;
            }
            // Modern ProseMirror nodes have types like paragraph, heading, bulletList, etc.
            if (in_array($type, ['paragraph', 'heading', 'bulletList', 'orderedList', 'blockquote', 'codeBlock', 'horizontalRule'], true)) {
                return false;
            }
        }

        return false;
    }

    private static function htmlToMarkdown(string $html): string
    {
        $html = mb_trim($html);
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument;
        @$doc->loadHTML(
            '<?xml encoding="UTF-8"><body>'.$html.'</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body) {
            return strip_tags($html);
        }

        return mb_trim(self::renderNode($body));
    }

    private static function renderNode(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $node->textContent;
        }

        if (! ($node instanceof DOMElement)) {
            return '';
        }

        $inner = self::renderChildren($node);

        return match ($node->tagName) {
            'body', 'div', 'span' => $inner,
            'p' => $inner."\n\n",
            'h1' => '# '.$inner."\n\n",
            'h2' => '## '.$inner."\n\n",
            'h3' => '### '.$inner."\n\n",
            'h4' => '#### '.$inner."\n\n",
            'h5' => '##### '.$inner."\n\n",
            'h6' => '###### '.$inner."\n\n",
            'strong', 'b' => '**'.$inner.'**',
            'em', 'i' => '*'.$inner.'*',
            'code' => $node->parentNode instanceof DOMElement && $node->parentNode->tagName === 'pre'
                ? $inner
                : '`'.$inner.'`',
            'a' => '['.$inner.']('.$node->getAttribute('href').')',
            'img' => '!['.$node->getAttribute('alt').']('.$node->getAttribute('src').')',
            'ul' => self::renderList($node, ordered: false),
            'ol' => self::renderList($node, ordered: true),
            'li' => $inner,
            'blockquote' => self::renderBlockquote($inner),
            'pre' => self::renderCodeBlock($node),
            'br' => "\n",
            'hr' => "---\n\n",
            default => $inner,
        };
    }

    private static function renderChildren(DOMNode $node): string
    {
        $result = '';
        foreach ($node->childNodes as $child) {
            $result .= self::renderNode($child);
        }

        return $result;
    }

    private static function renderList(DOMElement $node, bool $ordered): string
    {
        $items = [];
        $i = 1;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'li') {
                $prefix = $ordered ? ($i++).'. ' : '- ';
                $items[] = $prefix.mb_trim(self::renderChildren($child));
            }
        }

        return implode("\n", $items)."\n\n";
    }

    private static function renderBlockquote(string $inner): string
    {
        $lines = explode("\n", mb_trim($inner));

        return implode("\n", array_map(fn ($line) => '> '.$line, $lines))."\n\n";
    }

    private static function renderCodeBlock(DOMElement $node): string
    {
        $code = $node->getElementsByTagName('code')->item(0);
        $lang = '';

        if ($code instanceof DOMElement) {
            $class = $code->getAttribute('class');
            if (preg_match('/language-(\w+)/', $class, $m)) {
                $lang = $m[1];
            }
            $content = $code->textContent;
        } else {
            $content = $node->textContent;
        }

        return '```'.$lang."\n".$content."\n```\n\n";
    }
}
