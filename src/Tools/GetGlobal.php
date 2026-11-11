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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\Site;

#[Title('Get global set')]
#[Description('Returns the values of a global set (site-wide content such as footer text or contact details) in the same shape update_global accepts.')]
#[IsReadOnly]
final class GetGlobal extends ContentTool
{
    protected string $name = 'get_global';

    public function schema(JsonSchema $schema): array
    {
        return [
            'handle' => $schema->string()->description('Global set handle, from get_overview.')->required(),
            'site' => self::siteArgument($schema),
        ];
    }

    protected function respond(Request $request): ResponseFactory
    {
        $request->validate(['handle' => ['required', 'string'], 'site' => ['nullable', 'string']]);

        $variables = Find::globals($request->string('handle')->toString(), $this->site($request));

        return Response::structured([
            'handle' => $variables->handle(),
            'title' => $variables->title(),
            ...(Site::multiEnabled() ? ['site' => $variables->locale()] : []),
            'data' => AgentForm::ofBlueprint($variables->blueprint(), ContentWriter::values($variables)),
        ]);
    }
}
