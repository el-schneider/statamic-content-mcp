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

it('converts paragraph html to markdown', function () {
    $bard = [
        ['type' => 'text', 'text' => '<p>Hello world</p>'],
    ];

    expect(BardToMarkdown::convert($bard))->toBe('Hello world');
});

it('converts multiple paragraphs', function () {
    $bard = [
        ['type' => 'text', 'text' => '<p>First paragraph</p><p>Second paragraph</p>'],
    ];

    expect(BardToMarkdown::convert($bard))->toBe("First paragraph\n\nSecond paragraph");
});

it('converts headings', function () {
    $bard = [
        ['type' => 'text', 'text' => '<h1>Title</h1><h2>Subtitle</h2><h3>Section</h3>'],
    ];

    $expected = "# Title\n\n## Subtitle\n\n### Section";
    expect(BardToMarkdown::convert($bard))->toBe($expected);
});

it('converts inline marks', function () {
    $bard = [
        ['type' => 'text', 'text' => '<p>This is <strong>bold</strong> and <em>italic</em> and <code>code</code>.</p>'],
    ];

    expect(BardToMarkdown::convert($bard))->toBe('This is **bold** and *italic* and `code`.');
});

it('converts links', function () {
    $bard = [
        ['type' => 'text', 'text' => '<p>Visit <a href="https://example.com">our site</a>.</p>'],
    ];

    expect(BardToMarkdown::convert($bard))->toBe('Visit [our site](https://example.com).');
});

it('converts unordered lists', function () {
    $bard = [
        ['type' => 'text', 'text' => '<ul><li>First</li><li>Second</li></ul>'],
    ];

    expect(BardToMarkdown::convert($bard))->toBe("- First\n- Second");
});

it('converts ordered lists', function () {
    $bard = [
        ['type' => 'text', 'text' => '<ol><li>First</li><li>Second</li></ol>'],
    ];

    expect(BardToMarkdown::convert($bard))->toBe("1. First\n2. Second");
});

it('converts blockquotes', function () {
    $bard = [
        ['type' => 'text', 'text' => '<blockquote><p>A quote</p></blockquote>'],
    ];

    expect(BardToMarkdown::convert($bard))->toContain('> A quote');
});

it('converts code blocks', function () {
    $bard = [
        ['type' => 'text', 'text' => '<pre><code class="language-php">echo "hello";</code></pre>'],
    ];

    $result = BardToMarkdown::convert($bard);
    expect($result)->toContain('```php');
    expect($result)->toContain('echo "hello";');
    expect($result)->toContain('```');
});

it('converts horizontal rules', function () {
    $bard = [
        ['type' => 'text', 'text' => '<p>Before</p><hr><p>After</p>'],
    ];

    $result = BardToMarkdown::convert($bard);
    expect($result)->toContain('Before');
    expect($result)->toContain('---');
    expect($result)->toContain('After');
});

it('returns array when sets are present', function () {
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

it('handles multiple text nodes between sets', function () {
    $bard = [
        ['type' => 'text', 'text' => '<p>First</p>'],
        ['type' => 'text', 'text' => '<p>Second</p>'],
        ['type' => 'set', 'attrs' => ['id' => 'abc', 'values' => ['type' => 'cta', 'heading' => 'Click']]],
    ];

    $result = BardToMarkdown::convert($bard);

    expect($result)->toBeArray()
        ->and($result[0]['type'])->toBe('text')
        ->and($result[0]['text'])->toContain('First')
        ->and($result[0]['text'])->toContain('Second')
        ->and($result[1])->toBe(['type' => 'cta', 'heading' => 'Click']);
});

it('converts complex html with mixed elements', function () {
    $bard = [
        ['type' => 'text', 'text' => '<p>Intro paragraph.</p><h2>Why Statamic?</h2><p>Statamic is <strong>powerful</strong> and <em>flexible</em>.</p>'],
    ];

    $result = BardToMarkdown::convert($bard);
    expect($result)->toContain('Intro paragraph.');
    expect($result)->toContain('## Why Statamic?');
    expect($result)->toContain('**powerful**');
    expect($result)->toContain('*flexible*');
});
