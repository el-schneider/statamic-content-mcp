<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

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

#[Title('Delete asset')]
#[Description('Deletes an asset and its file for good; there is no trash. Content that uses it keeps a reference to a missing file. Only call this when the editor explicitly asks to delete.')]
#[IsDestructive]
#[IsIdempotent]
final class DeleteAsset extends ContentTool
{
    protected string $name = 'delete_asset';

    public function shouldRegister(): bool
    {
        return (bool) config('statamic.content-mcp.deletes');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Asset id, container::path.')->required(),
        ];
    }

    protected function respond(Request $request): Response|ResponseFactory
    {
        $request->validate(['id' => ['required', 'string']]);

        $asset = Find::asset($request->string('id')->toString());

        if (Gate::denies('delete', $asset)) {
            return Response::error('You may not delete this asset.');
        }

        // @phpstan-ignore notIdentical.alwaysTrue (Asset::delete() returns false when a deleting listener vetoes, unlike its docblock)
        NotSaved::unless($asset->delete() !== false, deletion: true);

        return Response::structured(['deleted' => $asset->id()]);
    }
}
