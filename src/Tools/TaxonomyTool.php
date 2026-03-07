<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Support\FieldValuePreparer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

final class TaxonomyTool extends Tool
{
    protected string $name = 'taxonomies';

    protected string $description = 'List taxonomies, and list, create, update, or delete taxonomy terms.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['list', 'get_terms', 'create_term', 'update_term', 'delete_term'])
                ->description('Action to perform.'),
            'taxonomy' => $schema->string()
                ->description('Taxonomy handle (required for all actions except list).'),
            'slug' => $schema->string()
                ->description('Term slug (required for update_term and delete_term).'),
            'data' => $schema->object()
                ->description('Field data for create_term/update_term. Must include title for create.'),
            'limit' => $schema->integer()
                ->description('Results per page for get_terms (default: 50).'),
            'offset' => $schema->integer()
                ->description('Pagination offset for get_terms.'),
        ];
    }

    public function shouldRegister(): bool
    {
        return (bool) config('statamic.content-mcp.tools.taxonomies', true);
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'action' => ['required', 'string', 'in:list,get_terms,create_term,update_term,delete_term'],
        ]);

        return match ($request->get('action')) {
            'list' => $this->list(),
            'get_terms' => $this->getTerms($request),
            'create_term' => $this->createTerm($request),
            'update_term' => $this->updateTerm($request),
            'delete_term' => $this->deleteTerm($request),
            default => Response::error('Unknown action.'),
        };
    }

    private function list(): Response
    {
        $taxonomies = Taxonomy::all()->map(fn ($taxonomy) => [
            'handle' => $taxonomy->handle(),
            'title' => $taxonomy->title(),
            'term_count' => $taxonomy->queryTerms()->count(),
        ])->values()->all();

        return Response::json(['data' => $taxonomies]);
    }

    private function getTerms(Request $request): Response
    {
        $request->validate([
            'taxonomy' => ['required', 'string'],
        ]);

        $handle = $request->get('taxonomy');
        $taxonomy = Taxonomy::findByHandle($handle);

        if (! $taxonomy) {
            return Response::error("Taxonomy [{$handle}] not found.");
        }

        $limit = (int) ($request->get('limit') ?? 50);
        $offset = (int) ($request->get('offset') ?? 0);

        $all = $taxonomy->queryTerms()->get();
        $total = $all->count();
        $paged = $all->slice($offset, $limit)->values();

        $terms = $paged->map(fn ($term) => [
            'slug' => $term->slug(),
            'title' => $term->get('title'),
            ...collect($term->data()->all())->except('title')->all(),
        ])->all();

        return Response::json([
            'data' => $terms,
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ]);
    }

    private function createTerm(Request $request): Response
    {
        $request->validate([
            'taxonomy' => ['required', 'string'],
            'data' => ['required', 'array'],
        ]);

        $handle = $request->get('taxonomy');
        $taxonomy = Taxonomy::findByHandle($handle);

        if (! $taxonomy) {
            return Response::error("Taxonomy [{$handle}] not found.");
        }

        $blueprint = $taxonomy->termBlueprint();
        $data = FieldValuePreparer::prepare($request->get('data'), $blueprint);

        $slug = $request->get('slug') ?? Str::slug($data['title'] ?? '');

        try {
            $laravelValidator = $blueprint->fields()
                ->addValues(array_merge($data, ['slug' => $slug]))
                ->validator()
                ->validator();

            // Exclude the auto-injected slug field — slug is term metadata set via $term->slug().
            $rules = collect($laravelValidator->getRules())->except('slug')->all();
            $laravelValidator->setRules($rules);

            $laravelValidator->validate();
        } catch (ValidationException $e) {
            $details = collect($e->errors())
                ->map(fn ($messages) => implode(', ', $messages))
                ->implode('; ');

            return Response::error("Validation failed: {$details}");
        }

        $term = Term::make()
            ->taxonomy($handle)
            ->slug($slug)
            ->data($data);

        $term->save();

        return Response::json([
            'created' => true,
            'taxonomy' => $handle,
            'slug' => $term->slug(),
            'title' => $term->get('title'),
        ]);
    }

    private function updateTerm(Request $request): Response
    {
        $request->validate([
            'taxonomy' => ['required', 'string'],
            'slug' => ['required', 'string'],
            'data' => ['required', 'array'],
        ]);

        $handle = $request->get('taxonomy');
        $taxonomy = Taxonomy::findByHandle($handle);

        if (! $taxonomy) {
            return Response::error("Taxonomy [{$handle}] not found.");
        }

        $slug = $request->get('slug');
        $term = Term::find($handle.'::'.$slug);

        if (! $term) {
            return Response::error("Term [{$slug}] not found in taxonomy [{$handle}].");
        }

        $blueprint = $taxonomy->termBlueprint();
        $incoming = FieldValuePreparer::prepare($request->get('data'), $blueprint);
        $merged = array_merge($term->data()->all(), $incoming);

        try {
            $laravelValidator = $blueprint->fields()
                ->addValues(array_merge($merged, ['slug' => $slug]))
                ->validator()
                ->validator();

            $rules = collect($laravelValidator->getRules())->except('slug')->all();
            $laravelValidator->setRules($rules);

            $laravelValidator->validate();
        } catch (ValidationException $e) {
            $details = collect($e->errors())
                ->map(fn ($messages) => implode(', ', $messages))
                ->implode('; ');

            return Response::error("Validation failed: {$details}");
        }

        $term->data($merged);
        $term->save();

        return Response::json([
            'updated' => true,
            'taxonomy' => $handle,
            'slug' => $term->slug(),
            'title' => $term->get('title'),
        ]);
    }

    private function deleteTerm(Request $request): Response
    {
        $request->validate([
            'taxonomy' => ['required', 'string'],
            'slug' => ['required', 'string'],
        ]);

        $handle = $request->get('taxonomy');
        $taxonomy = Taxonomy::findByHandle($handle);

        if (! $taxonomy) {
            return Response::error("Taxonomy [{$handle}] not found.");
        }

        $slug = $request->get('slug');
        $term = Term::find($handle.'::'.$slug);

        if (! $term) {
            return Response::error("Term [{$slug}] not found in taxonomy [{$handle}].");
        }

        $title = $term->get('title');
        $term->delete();

        return Response::json([
            'deleted' => true,
            'taxonomy' => $handle,
            'slug' => $slug,
            'title' => $title,
        ]);
    }
}
