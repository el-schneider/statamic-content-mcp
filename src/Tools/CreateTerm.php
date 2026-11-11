<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\ContentWriter;
use ElSchneider\ContentMcp\Content\Find;
use ElSchneider\ContentMcp\Content\NotFound;
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
use Statamic\Contracts\Taxonomies\Term as TermContract;

#[Title('Create taxonomy term')]
#[Description('Creates a term in a taxonomy, e.g. a new tag or category. Terms have no drafts: the term is live once created. `data` maps field handles from get_blueprint (pass `taxonomy`) to values; `title` is required, the slug is built from it unless you pass `data.slug`.')]
#[IsDestructive(false)]
#[IsIdempotent(false)]
final class CreateTerm extends ContentTool
{
    protected string $name = 'create_term';

    public function schema(JsonSchema $schema): array
    {
        return [
            'taxonomy' => $schema->string()->description('Taxonomy handle, from get_overview.')->required(),
            'site' => self::siteArgument($schema),
            'blueprint' => $schema->string()->description('Blueprint handle. Defaults to the taxonomy\'s default blueprint.'),
            'data' => $schema->object()->description('Field values keyed by field handle, e.g. {"title": "Gardening"}.')->required(),
        ];
    }

    protected function respond(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'taxonomy' => ['required', 'string'],
            'site' => ['nullable', 'string'],
            'blueprint' => ['nullable', 'string'],
            'data' => ['required', 'array'],
        ]);

        $taxonomy = Find::taxonomy($request->string('taxonomy')->toString());
        $site = $this->site($request);

        // The CP checks `create` for the form and `store` for the save (TermsController::create()/store()).
        if (Gate::denies('create', [TermContract::class, $taxonomy, $site]) || Gate::denies('store', [TermContract::class, $taxonomy, $site])) {
            return Response::error("You may not create terms in [{$taxonomy->handle()}].");
        }

        $handle = $request->filled('blueprint') ? $request->string('blueprint')->toString() : null;
        $blueprint = $taxonomy->termBlueprint($handle) ?? throw new NotFound("Blueprint [{$handle}] not found.");

        /** @var array<string, mixed> $data */
        $data = $request->array('data');

        return Response::structured(TermPresenter::present(ContentWriter::createTerm($this->user(), $taxonomy, $blueprint, $site, $data)));
    }
}
