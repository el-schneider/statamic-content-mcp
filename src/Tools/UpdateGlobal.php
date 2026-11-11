<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\AgentForm;
use ElSchneider\ContentMcp\Content\ContentWriter;
use ElSchneider\ContentMcp\Content\Find;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Statamic\Facades\Site;

#[Title('Update global set')]
#[Description('Changes values of a global set. Changes are live immediately; globals have no drafts. `data` works like in update_entry: field handles replace a field, dot paths patch one nested value.')]
#[IsDestructive]
#[IsIdempotent]
final class UpdateGlobal extends ContentTool
{
    protected string $name = 'update_global';

    public function schema(JsonSchema $schema): array
    {
        return [
            'handle' => $schema->string()->description('Global set handle, from get_overview.')->required(),
            'data' => $schema->object()->description('Field handles or dot paths mapped to new values.')->required(),
            'site' => self::siteArgument($schema),
        ];
    }

    protected function respond(Request $request): ResponseFactory
    {
        $request->validate([
            'handle' => ['required', 'string'],
            'data' => ['present', 'array'],
            'site' => ['nullable', 'string'],
        ]);

        /** @var array<string, mixed> $changes */
        $changes = $request->array('data');
        $variables = ContentWriter::updateGlobal(Find::globals($request->string('handle')->toString(), $this->site($request)), $changes);

        return Response::structured([
            'handle' => $variables->handle(),
            ...(Site::multiEnabled() ? ['site' => $variables->locale()] : []),
            'data' => AgentForm::ofBlueprint($variables->blueprint(), ContentWriter::values($variables)),
        ]);
    }
}
