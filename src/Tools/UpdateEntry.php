<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\ContentWriter;
use ElSchneider\ContentMcp\Content\EntryPresenter;
use ElSchneider\ContentMcp\Content\Find;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Title('Update entry')]
#[Description(<<<'TEXT'
    Changes field values of an existing entry. Does not publish or unpublish it: a published entry's changes go live unless the site keeps revisions, in which case they are saved as unpublished changes like in the Control Panel.
    `data` keys are field handles (the value replaces the whole field) or dot paths that patch one nested value without resending the rest. In a path, a segment is an object key, or inside a list of sets or rows the `id` of the item, e.g. {"title": "New title", "page_builder.lx3k2a.heading": "New heading"}. To add, remove or reorder sets, send the whole field; new sets go without an `id`.
    Returns the entry meta with `preview_url`.
    TEXT)]
#[IsDestructive]
#[IsIdempotent]
final class UpdateEntry extends ContentTool
{
    protected string $name = 'update_entry';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Entry id.')->required(),
            'data' => $schema->object()->description('Field handles or dot paths mapped to new values.')->required(),
            'slug' => $schema->string()->description('New URL slug.'),
            'date' => $schema->string()->description('New entry date for dated collections, e.g. "2025-01-31".'),
        ];
    }

    protected function respond(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => ['required', 'string'],
            'data' => ['present', 'array'],
            'slug' => ['nullable', 'string'],
            'date' => ['nullable', 'string'],
        ]);

        $entry = Find::entry($request->string('id')->toString());

        // The ability the CP's EntriesController::update() authorizes.
        if (Gate::denies('update', $entry)) {
            return Response::error('You may not edit this entry.');
        }

        /** @var array<string, mixed> $changes */
        $changes = [...$request->array('data'), ...array_filter($request->only(['slug', 'date']))];

        $entry = ContentWriter::updateEntry($this->user(), $entry, $changes);

        return Response::structured(EntryPresenter::meta($entry, withPreview: true));
    }
}
