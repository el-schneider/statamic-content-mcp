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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Entries\Collection;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Site;

#[Title('Find entries')]
#[Description('Lists or searches entries and returns short summaries (id, title, status, url…) plus the `total` number of matches. Without `query` it lists one collection: dated collections newest first, others by title. With `query` it matches title, slug and content text, case-insensitively, across every collection you may view unless `collection` is given, and returns a `snippet` around the first content match. Use get_entry with an id to read an entry\'s content.')]
#[IsReadOnly]
final class FindEntries extends ContentTool
{
    protected string $name = 'find_entries';

    public function schema(JsonSchema $schema): array
    {
        return [
            'collection' => $schema->string()->description('Collection handle, from get_overview.'),
            'query' => $schema->string()->description('Text to search for.'),
            'status' => $schema->string()->enum(['published', 'draft', 'scheduled', 'expired'])->description('Only entries with this status.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(20),
            'offset' => $schema->integer()->min(0)->default(0),
        ];
    }

    protected function respond(Request $request): ResponseFactory
    {
        $request->validate([
            'collection' => ['nullable', 'string'],
            'query' => ['nullable', 'string'],
            'status' => ['nullable', 'in:published,draft,scheduled,expired'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $collections = $request->filled('collection')
            ? collect([Find::collection($request->string('collection')->toString())])
            : Collections::all()->filter(fn (Collection $collection): bool => Gate::allows('view', $collection));

        $site = Site::default()->handle();
        $status = $request->filled('status') ? $request->string('status')->toString() : null;

        // shortcut: loads every entry of the collections to filter by policy and search in PHP;
        // move filtering into an entry query (or a Statamic search index) for very large collections.
        $entries = Entries::whereInCollection($collections->map->handle()->values()->all())
            ->filter(fn (Entry $entry): bool => $entry->locale() === $site
                && ($status === null || $entry->status() === $status)
                && Gate::allows('view', $entry));

        $entries = $collections->count() === 1 && $collections->first()->dated()
            ? $entries->sortByDesc(fn (Entry $entry): int => $entry->date()->getTimestamp())
            : $entries->sortBy(fn (Entry $entry): string => mb_strtolower((string) $entry->value('title')));

        $search = $request->filled('query') ? $request->string('query')->toString() : null;
        $results = [];

        foreach ($entries as $entry) {
            $snippet = $search === null ? null : self::snippet($entry->data()->all(), $search);

            if ($search === null || $snippet !== null || self::matches((string) $entry->value('title'), $search) || self::matches((string) $entry->slug(), $search)) {
                $results[] = [...EntryPresenter::summary($entry), ...($search === null ? [] : ['snippet' => $snippet])];
            }
        }

        return Response::structured([
            'total' => count($results),
            'entries' => array_slice($results, $request->integer('offset'), $request->integer('limit', 20)),
        ]);
    }

    private static function matches(string $text, string $search): bool
    {
        return mb_stripos($text, $search) !== false;
    }

    /**
     * The text around the first match in any string value, ignoring markup and
     * structural keys (ProseMirror node types, set types and ids).
     *
     * @param  array<array-key, mixed>  $data
     */
    private static function snippet(array $data, string $search): ?string
    {
        $found = null;

        array_walk_recursive($data, function (mixed $value, int|string $key) use ($search, &$found): void {
            if ($found !== null || ! is_string($value) || $key === 'type' || $key === 'id') {
                return;
            }

            $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));
            $position = mb_stripos($text, $search);

            if ($position !== false) {
                $start = max(0, $position - 60);
                $found = ($start > 0 ? '…' : '').mb_substr($text, $start, mb_strlen($search) + 120).($start + mb_strlen($search) + 120 < mb_strlen($text) ? '…' : '');
            }
        });

        return $found;
    }
}
