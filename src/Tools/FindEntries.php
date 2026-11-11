<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\EntryPresenter;
use ElSchneider\ContentMcp\Content\Find;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Entries\Collection as EntryCollection;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry as Entries;

#[Title('Find entries')]
#[Description('Lists or searches entries and returns short summaries (id, title, status, url…). `has_more` tells whether another page follows; there is no total count. Without `query` it lists one collection: dated collections newest first, others by title. With `query` it matches title, slug and content text, case-insensitively, across every collection you may view unless `collection` is given, and returns a `snippet` around the first content match. Use get_entry with an id to read an entry\'s content.')]
#[IsReadOnly]
final class FindEntries extends ContentTool
{
    protected string $name = 'find_entries';

    public function schema(JsonSchema $schema): array
    {
        return [
            'collection' => $schema->string()->description('Collection handle, from get_overview.'),
            'site' => self::siteArgument($schema),
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
            'site' => ['nullable', 'string'],
            'query' => ['nullable', 'string'],
            'status' => ['nullable', 'in:published,draft,scheduled,expired'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $collections = $request->filled('collection')
            ? collect([Find::collection($request->string('collection')->toString())])
            : Collections::all()->filter(fn (EntryCollection $collection): bool => Gate::allows('view', $collection));

        $query = Entries::query()
            // @phpstan-ignore method.notFound (Statamic's entry query contract is an empty interface; both drivers' builders have whereIn)
            ->whereIn('collection', $collections->map->handle()->values()->all())
            ->where('site', $this->site($request)->handle())
            ->when($request->filled('status'), fn ($query) => $query->whereStatus($request->string('status')->toString()));

        $collections->count() === 1 && $collections->first()->dated()
            ? $query->orderBy('date', 'desc')
            : $query->orderBy('title');

        $search = $request->filled('query') ? $request->string('query')->toString() : null;
        $limit = $request->integer('limit', 20);

        // The policy and the content match need the entry. The query resolves the ordered
        // ids once; entries are then loaded in chunks and checked one by one, until the
        // page and one more are found.
        // shortcut: a query matching few entries still reads every entry of the collections once;
        // use a Statamic search index for very large sites.
        $page = $query->pluck('id')
            ->chunk(200)
            ->lazy()
            ->flatMap(fn (Collection $ids): Collection => self::entries($ids->values()))
            ->filter(fn (Entry $entry): bool => Gate::allows('view', $entry))
            ->map(fn (Entry $entry): ?array => self::result($entry, $search))
            ->filter()
            ->skip($request->integer('offset'))
            ->take($limit + 1)
            ->values()
            ->all();

        return Response::structured([
            'entries' => array_slice($page, 0, $limit),
            'has_more' => count($page) > $limit,
        ]);
    }

    /**
     * The entries with these ids, in this order.
     *
     * @param  Collection<int, mixed>  $ids
     * @return Collection<int, Entry>
     */
    private static function entries(Collection $ids): Collection
    {
        // @phpstan-ignore method.notFound (see above)
        $entries = Entries::query()->whereIn('id', $ids->all())->get()->keyBy(fn (Entry $entry): string => $entry->id());

        return $ids->map(fn (mixed $id): mixed => $entries->get($id))->whereInstanceOf(Entry::class)->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function result(Entry $entry, ?string $search): ?array
    {
        if ($search === null) {
            return EntryPresenter::summary($entry);
        }

        $snippet = self::snippet($entry->data()->all(), $search);

        if ($snippet === null && ! self::matches((string) $entry->value('title'), $search) && ! self::matches((string) $entry->slug(), $search)) {
            return null;
        }

        return [...EntryPresenter::summary($entry), 'snippet' => $snippet];
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
