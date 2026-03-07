<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Support\EntryFormatter;
use ElSchneider\ContentMcp\Support\FieldValuePreparer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

final class EntryTool extends Tool
{
    protected string $name = 'entries';

    protected string $description = 'List, get, and create entries. Supports filtering, sorting, pagination, and draft-first publishing.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['list', 'get', 'create'])
                ->description('Action to perform.'),
            'collection' => $schema->string()
                ->description('Collection handle (required for list and create).'),
            'id' => $schema->string()
                ->description('Entry ID (for get).'),
            'slug' => $schema->string()
                ->description('Entry slug. For get: alternative to ID (requires collection). For create: sets the slug (auto-generated from title if omitted).'),
            'data' => $schema->object()
                ->description('Field data for create. Use blueprints.describe to discover available fields.'),
            'blueprint' => $schema->string()
                ->description('Blueprint handle for create (auto-detected if collection has only one).'),
            'published' => $schema->boolean()
                ->description('Set true to publish on create (default: false, creates as draft).'),
            'date' => $schema->string()
                ->description('Publish date for dated collections (ISO 8601, e.g. 2026-03-10).'),
            'status' => $schema->string()
                ->enum(['draft', 'published', 'scheduled', 'any'])
                ->description('Filter by status (for list). Default: any.'),
            'sort' => $schema->string()
                ->description('Sort field for list (prefix - for desc).'),
            'limit' => $schema->integer()
                ->description('Results per page (default: 20, max: 100).'),
            'offset' => $schema->integer()
                ->description('Pagination offset.'),
        ];
    }

    public function shouldRegister(): bool
    {
        return (bool) config('statamic.content-mcp.tools.entries', true);
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'action' => ['required', 'string', 'in:list,get,create'],
        ]);

        return match ($request->get('action')) {
            'list' => $this->list($request),
            'get' => $this->get($request),
            'create' => $this->create($request),
            default => Response::error('Unknown action.'),
        };
    }

    private function list(Request $request): Response
    {
        $request->validate([
            'collection' => ['required', 'string'],
        ]);

        $handle = $request->get('collection');

        $allowed = config('statamic.content-mcp.collections');
        if ($allowed !== null && ! in_array($handle, $allowed, strict: true)) {
            return Response::error("Collection [{$handle}] is not accessible.");
        }

        $collection = Collection::findByHandle($handle);
        if (! $collection) {
            return Response::error("Collection [{$handle}] not found.");
        }

        $status = $request->get('status', 'any');
        $sort = $request->get('sort');
        $limit = min(
            (int) ($request->get('limit') ?? config('statamic.content-mcp.pagination.default_limit', 20)),
            (int) config('statamic.content-mcp.pagination.max_limit', 100)
        );
        $offset = (int) ($request->get('offset') ?? 0);

        $query = $collection->queryEntries();

        if ($status !== 'any') {
            $query->whereStatus($status);
        }

        if ($sort) {
            $desc = str_starts_with($sort, '-');
            $field = $desc ? mb_substr($sort, 1) : $sort;
            $query->orderBy($field, $desc ? 'desc' : 'asc');
        } elseif ($collection->dated()) {
            $query->orderBy('date', 'desc');
        } else {
            $query->orderBy('title', 'asc');
        }

        $all = $query->get();
        $total = $all->count();
        $paged = $all->slice($offset, $limit)->values();

        return Response::json([
            'data' => $paged->map(fn ($entry) => EntryFormatter::forList($entry))->all(),
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ]);
    }

    private function get(Request $request): Response
    {
        $id = $request->get('id');
        $slug = $request->get('slug');
        $collectionHandle = $request->get('collection');

        if (! $id && ! $slug) {
            return Response::error('Either id or slug is required for get.');
        }

        $entry = null;

        if ($id) {
            $entry = Entry::find($id);
        } elseif ($collectionHandle) {
            $collection = Collection::findByHandle($collectionHandle);
            if (! $collection) {
                return Response::error("Collection [{$collectionHandle}] not found.");
            }
            $entry = $collection->queryEntries()->where('slug', $slug)->first();
        } else {
            return Response::error('Collection is required when using slug.');
        }

        if (! $entry) {
            return Response::error('Entry not found.');
        }

        $allowed = config('statamic.content-mcp.collections');
        if ($allowed !== null && ! in_array($entry->collectionHandle(), $allowed, strict: true)) {
            return Response::error("Collection [{$entry->collectionHandle()}] is not accessible.");
        }

        return Response::json(EntryFormatter::forGet($entry));
    }

    private function create(Request $request): Response
    {
        $request->validate([
            'collection' => ['required', 'string'],
            'data' => ['required', 'array'],
        ]);

        $handle = $request->get('collection');

        $allowed = config('statamic.content-mcp.collections');
        if ($allowed !== null && ! in_array($handle, $allowed, strict: true)) {
            return Response::error("Collection [{$handle}] is not accessible.");
        }

        $collection = Collection::findByHandle($handle);
        if (! $collection) {
            return Response::error("Collection [{$handle}] not found.");
        }

        $blueprintHandle = $request->get('blueprint')
            ?? $collection->entryBlueprints()->first()?->handle();
        $blueprint = $collection->entryBlueprint($blueprintHandle);

        if (! $blueprint) {
            return Response::error('Blueprint not found.');
        }

        $data = FieldValuePreparer::prepare($request->get('data'), $blueprint);

        $date = $collection->dated()
            ? ($request->get('date') ?? now()->toDateString())
            : null;

        try {
            $laravelValidator = $blueprint->fields()
                ->addValues($data)
                ->validator()
                ->validator();

            // Exclude the auto-injected date field — date is entry metadata set via $entry->date().
            if ($collection->dated()) {
                $rules = collect($laravelValidator->getRules())->except('date')->all();
                $laravelValidator->setRules($rules);
            }

            $laravelValidator->validate();
        } catch (ValidationException $e) {
            $details = collect($e->errors())
                ->map(fn ($messages) => implode(', ', $messages))
                ->implode('; ');

            return Response::error("Validation failed: {$details}");
        }

        $entry = Entry::make()
            ->collection($collection)
            ->blueprint($blueprintHandle);

        $slug = $request->get('slug') ?? Str::slug($data['title'] ?? '');
        if ($slug) {
            $entry->slug($slug);
        }

        $published = $request->get('published')
            ?? config('statamic.content-mcp.default_published', false);
        $entry->published((bool) $published);

        if ($date) {
            $entry->date($date);
        }

        $entry->data($data);
        $entry->save();

        return Response::json(EntryFormatter::forCreate($entry));
    }
}
