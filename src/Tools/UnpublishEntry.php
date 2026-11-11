<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

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
use Statamic\Entries\Entry;

#[Title('Unpublish entry')]
#[Description('Turns a published entry back into a draft, which takes it off the website. Only call this when the editor explicitly asks.')]
#[IsDestructive]
#[IsIdempotent]
final class UnpublishEntry extends ContentTool
{
    protected string $name = 'unpublish_entry';

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
            return Response::error('You may not unpublish this entry.');
        }

        $unpublished = $entry->unpublish(['user' => $this->user()]);

        if (! $unpublished instanceof Entry) {
            return Response::error('The entry could not be unpublished.');
        }

        return Response::structured(EntryPresenter::meta($unpublished, withPreview: true));
    }
}
