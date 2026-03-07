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

it('converts simple markdown to bard text node', function () {
    $result = MarkdownToBard::convert('Hello world');

    expect($result)->toBeArray()
        ->and($result)->toHaveCount(1)
        ->and($result[0]['type'])->toBe('text')
        ->and($result[0]['text'])->toContain('<p>Hello world</p>');
});

it('converts markdown headings to html', function () {
    $result = MarkdownToBard::convert("# Title\n\nParagraph");

    expect($result[0]['text'])->toContain('<h1>Title</h1>')
        ->and($result[0]['text'])->toContain('<p>Paragraph</p>');
});

it('converts markdown with bold and italic', function () {
    $result = MarkdownToBard::convert('This is **bold** and *italic*');

    expect($result[0]['text'])->toContain('<strong>bold</strong>')
        ->and($result[0]['text'])->toContain('<em>italic</em>');
});

it('converts block array with text blocks', function () {
    $blocks = [
        ['type' => 'text', 'text' => '# Hello'],
        ['type' => 'text', 'text' => 'World'],
    ];

    $result = MarkdownToBard::convert($blocks);

    expect($result)->toHaveCount(2)
        ->and($result[0]['type'])->toBe('text')
        ->and($result[0]['text'])->toContain('<h1>Hello</h1>')
        ->and($result[1]['type'])->toBe('text')
        ->and($result[1]['text'])->toContain('<p>World</p>');
});

it('converts block array with sets', function () {
    $blocks = [
        ['type' => 'text', 'text' => 'Before'],
        ['type' => 'image', 'asset' => ['photo.jpg'], 'caption' => 'A photo'],
        ['type' => 'text', 'text' => 'After'],
    ];

    $result = MarkdownToBard::convert($blocks);

    expect($result)->toHaveCount(3)
        ->and($result[0]['type'])->toBe('text')
        ->and($result[0]['text'])->toContain('<p>Before</p>')
        ->and($result[1]['type'])->toBe('set')
        ->and($result[1]['attrs']['values']['type'])->toBe('image')
        ->and($result[1]['attrs']['values']['caption'])->toBe('A photo')
        ->and($result[1]['attrs'])->toHaveKey('id')
        ->and($result[2]['type'])->toBe('text');
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
