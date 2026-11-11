<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\EntryPresenter;
use ElSchneider\ContentMcp\Content\Find;
use ElSchneider\ContentMcp\Content\NotSaved;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Statamic\Entries\Entry;

#[Title('Publish entry')]
#[Description('Publishes an entry, including unpublished changes when the site keeps revisions, so it goes live on the website. Only call this when the editor explicitly asks to publish.')]
#[IsDestructive]
#[IsIdempotent]
final class PublishEntry extends ContentTool
{
    protected string $name = 'publish_entry';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Entry id.')->required(),
        ];
    }

    protected function respond(Request $request): Response|ResponseFactory
    {
        $request->validate(['id' => ['required', 'string']]);

        $entry = Find::entry($request->string('id')->toString());

        if (Gate::denies('publish', $entry)) {
            return Response::error('You may not publish this entry.');
        }

        $published = $entry->publish(['user' => $this->user()]);

        if (! $published instanceof Entry) {
            throw new NotSaved;
        }

        return Response::structured(EntryPresenter::meta($published, withPreview: false));
    }
}
