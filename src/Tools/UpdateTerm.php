<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\ContentWriter;
use ElSchneider\ContentMcp\Content\Find;
use ElSchneider\ContentMcp\Content\TermPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Title('Update taxonomy term')]
#[Description('Changes field values of a taxonomy term; changes are live at once. `data` works like in update_entry: field handles replace a field, dot paths patch one nested value. `data.slug` renames the term, which changes the value entries store to reference it.')]
#[IsDestructive]
#[IsIdempotent]
final class UpdateTerm extends ContentTool
{
    protected string $name = 'update_term';

    public function schema(JsonSchema $schema): array
    {
        return [
            'taxonomy' => $schema->string()->description('Taxonomy handle, from get_overview.')->required(),
            'slug' => $schema->string()->description('Current slug of the term.')->required(),
            'site' => self::siteArgument($schema),
            'data' => $schema->object()->description('Field handles or dot paths mapped to new values.')->required(),
        ];
    }

    protected function respond(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'taxonomy' => ['required', 'string'],
            'slug' => ['required', 'string'],
            'site' => ['nullable', 'string'],
            'data' => ['present', 'array'],
        ]);

        $term = Find::term($request->string('taxonomy')->toString(), $request->string('slug')->toString(), $this->site($request));

        // The ability the CP's TermsController::update() authorizes.
        if (Gate::denies('update', $term)) {
            return Response::error('You may not edit this term.');
        }

        /** @var array<string, mixed> $changes */
        $changes = $request->array('data');

        return Response::structured(TermPresenter::present(ContentWriter::updateTerm($this->user(), $term, $changes)));
    }
}
