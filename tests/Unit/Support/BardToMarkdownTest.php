<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Support\BardToMarkdown;

it('returns empty string for empty array', function () {
    expect(BardToMarkdown::convert([]))->toBe('');
});

it('returns empty string for non-array value', function () {
    expect(BardToMarkdown::convert(null))->toBe('');
    expect(BardToMarkdown::convert(''))->toBe('');
});

// --- ProseMirror format (modern) ---

it('converts prosemirror paragraph to markdown', function () {
    $bard = [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello world']]],
    ];

    expect(BardToMarkdown::convert($bard))->toBe('Hello world');
});

it('converts prosemirror headings to markdown', function () {
    $bard = [
        ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Title']]],
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Subtitle']]],
    ];

    $result = BardToMarkdown::convert($bard);
    expect($result)->toContain('# Title')
        ->and($result)->toContain('## Subtitle');
});

it('converts prosemirror bold and italic marks to markdown', function () {
    $bard = [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'This is '],
            ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' and '],
            ['type' => 'text', 'text' => 'italic', 'marks' => [['type' => 'italic']]],
            ['type' => 'text', 'text' => '.'],
        ]],
    ];

    expect(BardToMarkdown::convert($bard))->toBe('This is **bold** and *italic*.');
});

it('converts prosemirror links to markdown', function () {
    $bard = [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Visit '],
            ['type' => 'text', 'text' => 'our site', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]]],
            ['type' => 'text', 'text' => '.'],
        ]],
    ];

    expect(BardToMarkdown::convert($bard))->toBe('Visit [our site](https://example.com).');
});

it('converts prosemirror bullet list to markdown', function () {
    $bard = [
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First']]]]],
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second']]]]],
        ]],
    ];

    expect(BardToMarkdown::convert($bard))->toContain('First')
        ->and(BardToMarkdown::convert($bard))->toContain('Second');
});

it('converts prosemirror blockquote to markdown', function () {
    $bard = [
        ['type' => 'blockquote', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A quote']]],
        ]],
    ];

    expect(BardToMarkdown::convert($bard))->toContain('> A quote');
});

it('converts prosemirror with sets', function () {
    $bard = [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before set']]],
        ['type' => 'set', 'attrs' => ['id' => 'abc', 'values' => ['type' => 'image', 'asset' => ['photo.jpg'], 'caption' => 'A photo']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After set']]],
    ];

    $result = BardToMarkdown::convert($bard);

    expect($result)->toBeArray()
        ->and($result)->toHaveCount(3)
        ->and($result[0])->toBe(['type' => 'text', 'text' => 'Before set'])
        ->and($result[1])->toBe(['type' => 'image', 'asset' => ['photo.jpg'], 'caption' => 'A photo'])
        ->and($result[2])->toBe(['type' => 'text', 'text' => 'After set']);
});

// --- Legacy format (backwards compatibility) ---

it('converts legacy html text nodes to markdown', function () {
    $bard = [
        ['type' => 'text', 'text' => '<p>Hello world</p>'],
    ];

    expect(BardToMarkdown::convert($bard))->toBe('Hello world');
});

it('converts legacy html headings to markdown', function () {
    $bard = [
        ['type' => 'text', 'text' => '<h1>Title</h1><h2>Subtitle</h2><h3>Section</h3>'],
    ];

    $expected = "# Title\n\n## Subtitle\n\n### Section";
    expect(BardToMarkdown::convert($bard))->toBe($expected);
});

it('converts legacy html with inline marks', function () {
    $bard = [
        ['type' => 'text', 'text' => '<p>This is <strong>bold</strong> and <em>italic</em> and <code>code</code>.</p>'],
    ];

    expect(BardToMarkdown::convert($bard))->toBe('This is **bold** and *italic* and `code`.');
});

it('converts legacy html with sets', function () {
    $bard = [
        ['type' => 'text', 'text' => '<p>Before set</p>'],
        ['type' => 'set', 'attrs' => ['id' => 'abc', 'values' => ['type' => 'image', 'asset' => ['photo.jpg'], 'caption' => 'A photo']]],
        ['type' => 'text', 'text' => '<p>After set</p>'],
    ];

    $result = BardToMarkdown::convert($bard);

    expect($result)->toBeArray()
        ->and($result)->toHaveCount(3)
        ->and($result[0])->toBe(['type' => 'text', 'text' => 'Before set'])
        ->and($result[1])->toBe(['type' => 'image', 'asset' => ['photo.jpg'], 'caption' => 'A photo'])
        ->and($result[2])->toBe(['type' => 'text', 'text' => 'After set']);
});

it('converts legacy html with complex mixed elements', function () {
    $bard = [
        ['type' => 'text', 'text' => '<p>Intro paragraph.</p><h2>Why Statamic?</h2><p>Statamic is <strong>powerful</strong> and <em>flexible</em>.</p>'],
    ];

    $result = BardToMarkdown::convert($bard);
    expect($result)->toContain('Intro paragraph.');
    expect($result)->toContain('## Why Statamic?');
    expect($result)->toContain('**powerful**');
    expect($result)->toContain('*flexible*');
});
