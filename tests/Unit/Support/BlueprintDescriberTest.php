<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Support\BlueprintDescriber;
use Statamic\Facades\Blueprint;

function makeBlueprint(array $fields): Statamic\Fields\Blueprint
{
    return Blueprint::make('test')->setContents(['fields' => $fields]);
}

it('describes basic text fields', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields)->toHaveCount(1)
        ->and($fields[0]['handle'])->toBe('title')
        ->and($fields[0]['display'])->toBe('Title')
        ->and($fields[0]['type'])->toBe('text');
});

it('marks required fields via validate array', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title', 'validate' => ['required']]],
        ['handle' => 'subtitle', 'field' => ['type' => 'text', 'display' => 'Subtitle']],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields[0])->toHaveKey('required', true)
        ->and($fields[1])->not->toHaveKey('required');
});

it('marks required fields via required boolean config', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title', 'required' => true]],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields[0])->toHaveKey('required', true);
});

it('skips section fields', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'section_one', 'field' => ['type' => 'section', 'display' => 'Section']],
        ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields)->toHaveCount(1)
        ->and($fields[0]['handle'])->toBe('title');
});

it('includes instructions when set', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title', 'instructions' => 'Enter the title.']],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields[0])->toHaveKey('instructions', 'Enter the title.');
});

it('omits instructions when not set', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields[0])->not->toHaveKey('instructions');
});

it('includes character_limit for text fields', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'meta', 'field' => ['type' => 'text', 'display' => 'Meta', 'character_limit' => 60]],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields[0])->toHaveKey('character_limit', 60);
});

it('includes options for select fields', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'status', 'field' => ['type' => 'select', 'display' => 'Status', 'options' => ['draft', 'published']]],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields[0])->toHaveKey('options', ['draft', 'published']);
});

it('includes container and max_files for assets fields', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'image', 'field' => ['type' => 'assets', 'display' => 'Image', 'container' => 'assets', 'max_files' => 1]],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields[0])
        ->toHaveKey('container', 'assets')
        ->toHaveKey('max_files', 1);
});

it('includes taxonomies and create for terms fields', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'tags', 'field' => ['type' => 'terms', 'display' => 'Tags', 'taxonomies' => ['tags'], 'create' => true]],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields[0])
        ->toHaveKey('taxonomies', ['tags'])
        ->toHaveKey('create', true);
});

it('includes accepts markdown for bard fields', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'content', 'field' => ['type' => 'bard', 'display' => 'Content']],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields[0])->toHaveKey('accepts', 'markdown');
});

it('describes bard sets recursively', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'content', 'field' => [
            'type' => 'bard',
            'display' => 'Content',
            'sets' => [
                'image' => [
                    'display' => 'Image',
                    'fields' => [
                        ['handle' => 'asset', 'field' => ['type' => 'assets', 'display' => 'Asset', 'container' => 'assets', 'max_files' => 1]],
                        ['handle' => 'caption', 'field' => ['type' => 'text', 'display' => 'Caption']],
                    ],
                ],
            ],
        ]],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields[0])->toHaveKey('sets')
        ->and($fields[0]['sets'])->toHaveCount(1)
        ->and($fields[0]['sets'][0]['handle'])->toBe('image')
        ->and($fields[0]['sets'][0]['display'])->toBe('Image')
        ->and($fields[0]['sets'][0]['fields'])->toHaveCount(2)
        ->and($fields[0]['sets'][0]['fields'][0]['handle'])->toBe('asset')
        ->and($fields[0]['sets'][0]['fields'][0]['container'])->toBe('assets')
        ->and($fields[0]['sets'][0]['fields'][1]['handle'])->toBe('caption');
});

it('describes bard grouped sets by flattening groups', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'content', 'field' => [
            'type' => 'bard',
            'display' => 'Content',
            'sets' => [
                'group_one' => [
                    'sets' => [
                        'image' => ['display' => 'Image', 'fields' => [
                            ['handle' => 'src', 'field' => ['type' => 'assets', 'display' => 'Source']],
                        ]],
                    ],
                ],
            ],
        ]],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields[0]['sets'])->toHaveCount(1)
        ->and($fields[0]['sets'][0]['handle'])->toBe('image');
});

it('describes replicator sets', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'builder', 'field' => [
            'type' => 'replicator',
            'display' => 'Builder',
            'sets' => [
                'hero' => [
                    'display' => 'Hero',
                    'fields' => [
                        ['handle' => 'heading', 'field' => ['type' => 'text', 'display' => 'Heading', 'validate' => ['required']]],
                    ],
                ],
            ],
        ]],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields[0])->toHaveKey('sets')
        ->and($fields[0]['sets'][0]['handle'])->toBe('hero')
        ->and($fields[0]['sets'][0]['fields'][0]['handle'])->toBe('heading')
        ->and($fields[0]['sets'][0]['fields'][0])->toHaveKey('required', true);
});

it('describes grid sub-fields', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'links', 'field' => [
            'type' => 'grid',
            'display' => 'Links',
            'fields' => [
                ['handle' => 'label', 'field' => ['type' => 'text', 'display' => 'Label']],
                ['handle' => 'url', 'field' => ['type' => 'text', 'display' => 'URL']],
            ],
        ]],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields[0])->toHaveKey('fields')
        ->and($fields[0]['fields'])->toHaveCount(2)
        ->and($fields[0]['fields'][0]['handle'])->toBe('label')
        ->and($fields[0]['fields'][1]['handle'])->toBe('url');
});

it('includes min and max for integer fields', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'count', 'field' => ['type' => 'integer', 'display' => 'Count', 'min' => 1, 'max' => 100]],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields[0])
        ->toHaveKey('min', 1)
        ->toHaveKey('max', 100);
});

it('does not include sets key when bard has no sets', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'content', 'field' => ['type' => 'bard', 'display' => 'Content']],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields[0])->not->toHaveKey('sets');
});

it('skips section fields inside grid', function () {
    $blueprint = makeBlueprint([
        ['handle' => 'row', 'field' => [
            'type' => 'grid',
            'display' => 'Row',
            'fields' => [
                ['handle' => 'sep', 'field' => ['type' => 'section', 'display' => 'Separator']],
                ['handle' => 'value', 'field' => ['type' => 'text', 'display' => 'Value']],
            ],
        ]],
    ]);

    $fields = BlueprintDescriber::describe($blueprint);

    expect($fields[0]['fields'])->toHaveCount(1)
        ->and($fields[0]['fields'][0]['handle'])->toBe('value');
});
