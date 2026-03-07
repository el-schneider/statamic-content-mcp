<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Support\MarkdownToBard;

it('returns null for null value', function () {
    expect(MarkdownToBard::convert(null))->toBeNull();
});

it('returns null for empty string', function () {
    expect(MarkdownToBard::convert(''))->toBeNull();
});

it('returns null for empty array', function () {
    expect(MarkdownToBard::convert([]))->toBeNull();
});

it('converts simple markdown to prosemirror paragraph node', function () {
    $result = MarkdownToBard::convert('Hello world');

    expect($result)->toBeArray()
        ->and($result)->toHaveCount(1)
        ->and($result[0]['type'])->toBe('paragraph')
        ->and($result[0]['content'][0]['type'])->toBe('text')
        ->and($result[0]['content'][0]['text'])->toBe('Hello world');
});

it('converts markdown headings to prosemirror heading nodes', function () {
    $result = MarkdownToBard::convert("# Title\n\nParagraph");

    expect($result[0]['type'])->toBe('heading')
        ->and($result[0]['attrs']['level'])->toBe(1)
        ->and($result[0]['content'][0]['text'])->toBe('Title')
        ->and($result[1]['type'])->toBe('paragraph')
        ->and($result[1]['content'][0]['text'])->toBe('Paragraph');
});

it('converts markdown with bold and italic to prosemirror marks', function () {
    $result = MarkdownToBard::convert('This is **bold** and *italic*');

    $content = $result[0]['content'];
    $boldNode = collect($content)->first(fn ($n) => isset($n['marks']) && $n['marks'][0]['type'] === 'bold');
    $italicNode = collect($content)->first(fn ($n) => isset($n['marks']) && $n['marks'][0]['type'] === 'italic');

    expect($boldNode['text'])->toBe('bold')
        ->and($italicNode['text'])->toBe('italic');
});

it('converts block array with text blocks', function () {
    $blocks = [
        ['type' => 'text', 'text' => '# Hello'],
        ['type' => 'text', 'text' => 'World'],
    ];

    $result = MarkdownToBard::convert($blocks);

    // First block produces a heading node
    $heading = collect($result)->first(fn ($n) => $n['type'] === 'heading');
    expect($heading)->not->toBeNull()
        ->and($heading['content'][0]['text'])->toBe('Hello');

    // Second block produces a paragraph node
    $paragraph = collect($result)->first(fn ($n) => $n['type'] === 'paragraph');
    expect($paragraph)->not->toBeNull()
        ->and($paragraph['content'][0]['text'])->toBe('World');
});

it('converts block array with sets', function () {
    $blocks = [
        ['type' => 'text', 'text' => 'Before'],
        ['type' => 'image', 'asset' => ['photo.jpg'], 'caption' => 'A photo'],
        ['type' => 'text', 'text' => 'After'],
    ];

    $result = MarkdownToBard::convert($blocks);

    // Find the set node
    $set = collect($result)->first(fn ($n) => $n['type'] === 'set');
    expect($set)->not->toBeNull()
        ->and($set['attrs']['values']['type'])->toBe('image')
        ->and($set['attrs']['values']['caption'])->toBe('A photo')
        ->and($set['attrs'])->toHaveKey('id');

    // Find paragraph nodes before and after
    $paragraphs = collect($result)->filter(fn ($n) => $n['type'] === 'paragraph')->values();
    expect($paragraphs)->toHaveCount(2);
});

it('generates set ids when not provided', function () {
    $blocks = [
        ['type' => 'cta', 'heading' => 'Click'],
    ];

    $result = MarkdownToBard::convert($blocks);

    expect($result[0]['attrs']['id'])->toStartWith('set-');
});

it('preserves set ids when provided', function () {
    $blocks = [
        ['type' => 'cta', 'id' => 'my-id', 'heading' => 'Click'],
    ];

    $result = MarkdownToBard::convert($blocks);

    expect($result[0]['attrs']['id'])->toBe('my-id')
        ->and($result[0]['attrs']['values'])->not->toHaveKey('id');
});
