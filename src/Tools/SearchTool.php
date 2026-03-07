<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Support\EntryFormatter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

#[IsReadOnly]
final class SearchTool extends Tool
{
    protected string $name = 'search';

    protected string $description = 'Search entries across collections by query string. Matches against title and other indexed fields.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search query string (required).'),
            'collection' => $schema->string()
                ->description('Limit search to a specific collection.'),
            'status' => $schema->string()
                ->enum(['draft', 'published', 'scheduled', 'any'])
                ->description('Filter by status. Default: any.'),
            'limit' => $schema->integer()
                ->description('Max results (default: 20, max: 100).'),
        ];
    }

    public function shouldRegister(): bool
    {
        return (bool) config('statamic.content-mcp.tools.search', true);
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'query' => ['required', 'string', 'min:1'],
        ]);

        $query = $request->get('query');
        $collectionHandle = $request->get('collection');
        $status = $request->get('status', 'any');
        $limit = min(
            (int) ($request->get('limit') ?? config('statamic.content-mcp.pagination.default_limit', 20)),
            (int) config('statamic.content-mcp.pagination.max_limit', 100)
        );

        $allowed = config('statamic.content-mcp.collections');

        if ($collectionHandle) {
            if ($allowed !== null && ! in_array($collectionHandle, $allowed, strict: true)) {
                return Response::error("Collection [{$collectionHandle}] is not accessible.");
            }

            $collection = Collection::findByHandle($collectionHandle);
            if (! $collection) {
                return Response::error("Collection [{$collectionHandle}] not found.");
            }

            $entryQuery = $collection->queryEntries();
        } else {
            $entryQuery = Entry::query();
        }

        $entryQuery->where('title', 'like', '%'.$query.'%');

        if ($status !== 'any') {
            $entryQuery->whereStatus($status);
        }

        $results = $entryQuery->limit($limit)->get();

        // Filter by allowed collections when searching across all
        if (! $collectionHandle && $allowed !== null) {
            $results = $results->filter(
                fn ($entry) => in_array($entry->collectionHandle(), $allowed, strict: true)
            )->values();
        }

        return Response::json([
            'data' => $results->map(fn ($entry) => EntryFormatter::forList($entry))->all(),
            'total' => $results->count(),
        ]);
    }
}
