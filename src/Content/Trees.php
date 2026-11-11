<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Content;

use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Fields\Fields;
use Statamic\Structures\BranchIdGenerator;
use Statamic\Structures\CollectionTree;
use Statamic\Structures\NavTree;

/**
 * Navigation trees and the page trees of structured collections, as stored:
 * nested branches of {id, entry?, title?, url?, data?, children?}.
 */
final class Trees
{
    /**
     * A navigation tree as the model reads and writes it. A node linking an entry
     * the user may not view shows only its id; it cannot be changed or removed.
     *
     * @return list<array<string, mixed>>
     */
    public static function navigation(NavTree $tree): array
    {
        return self::presentNodes(self::nodeFields($tree), $tree, $tree->tree());
    }

    /**
     * Replaces the tree of a navigation, like the CP's NavigationTreeController::update():
     * nodes with an `id` update the stored node, nodes without one are new, nodes left
     * out are removed. What the client sends back unchanged stays as stored.
     *
     * @param  list<mixed>  $nodes
     */
    public static function updateNavigation(NavTree $tree, array $nodes): NavTree
    {
        $stored = self::byId($tree->tree());
        $nav = $tree->structure();
        $branches = self::branches(self::nodeFields($tree), $tree, $nodes, $stored, 'tree.');
        $kept = self::byId($branches);

        foreach ($stored as $id => $branch) {
            if (! isset($kept[$id]) && self::hiddenEntry($branch)) {
                throw ValidationException::withMessages(['tree' => ["Node [{$id}] links to an entry you may not view. Keep it in the tree."]]);
            }
        }

        if (($maxDepth = $nav->maxDepth()) !== null && self::depth($branches) > $maxDepth) {
            throw ValidationException::withMessages(['tree' => ["This navigation allows at most {$maxDepth} levels."]]);
        }

        self::save($tree, $branches);

        return $tree;
    }

    /**
     * Moves an entry in its collection's page tree, like dragging it in the CP's tree view.
     */
    public static function moveEntry(Entry $entry, ?Entry $parent, ?Entry $before, ?Entry $after): void
    {
        $collection = $entry->collection();
        $structure = $collection->structure();
        $tree = $structure?->in($entry->locale());

        if (! $tree instanceof CollectionTree) {
            throw ValidationException::withMessages(['id' => ["The collection [{$collection->handle()}] has no page tree."]]);
        }

        $branches = $tree->tree();
        $branch = self::find($branches, $entry->id());
        $rest = self::remove($branches, $entry->id());
        // With a root page, it is the first page at the top level and has no children.
        $root = $structure->expectsRoot() ? ($branches[0]['entry'] ?? null) : null;
        $siblings = $parent === null ? $rest : (self::find($rest, $parent->id())['children'] ?? []);
        $sibling = $before ?? $after;

        $error = match (true) {
            $branch === null => ['id', 'The entry is not in the page tree.'],
            $entry->id() === $root => ['id', 'The root page cannot be moved.'],
            $parent !== null && self::find($branches, $parent->id()) === null => ['parent', 'The parent is not in the page tree.'],
            $parent !== null && $parent->id() === $root => ['parent', 'The root page cannot have children.'],
            $parent !== null && self::find([$branch], $parent->id()) !== null => ['parent', 'An entry cannot move below itself.'],
            $sibling !== null && ! in_array($sibling->id(), array_column($siblings, 'entry'), true) => [$before !== null ? 'before' : 'after', 'The sibling must be a child of the new parent.'],
            $before !== null && $before->id() === $root => ['before', 'Nothing can move before the root page.'],
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages([$error[0] => [$error[1]]]);
        }

        /** @var array<array-key, mixed> $branch */
        $moved = self::insert($rest, $branch, $parent?->id(), $before?->id(), $after?->id());
        $depth = ($parent === null ? 0 : (int) self::level($branches, $parent->id())) + self::depth([$branch]);

        if (($maxDepth = $structure->maxDepth()) !== null && $depth > $maxDepth) {
            throw ValidationException::withMessages(['parent' => ["This collection's tree allows at most {$maxDepth} levels."]]);
        }

        self::assertUniqueUris($tree, $moved);
        self::save($tree, $moved);
    }

    /**
     * @param  list<array<array-key, mixed>>  $branches
     */
    private static function save(NavTree|CollectionTree $tree, array $branches): void
    {
        $before = $tree->tree();

        // A cancelled save must not leave the changed tree in the request's cached instance.
        if (! $tree->tree($branches)->save()) {
            $tree->tree($before);

            throw new NotSaved;
        }
    }

    /**
     * @param  list<array<array-key, mixed>>  $branches
     * @return list<array<string, mixed>>
     */
    private static function presentNodes(Fields $fields, NavTree $tree, array $branches): array
    {
        return array_map(fn (array $branch): array => [
            ...self::presentNode($fields, $tree, $branch),
            'children' => self::presentNodes($fields, $tree, $branch['children'] ?? []),
        ], $branches);
    }

    /**
     * @param  array<array-key, mixed>  $branch
     * @return array<string, mixed>
     */
    private static function presentNode(Fields $fields, NavTree $tree, array $branch): array
    {
        if (self::hiddenEntry($branch)) {
            return ['id' => $branch['id'] ?? null, 'restricted' => true];
        }

        $entry = self::entry($branch, $tree);

        return [
            // Legacy branches without an id are treated as new nodes when the tree is updated.
            'id' => $branch['id'] ?? null,
            'title' => $branch['title'] ?? $entry?->value('title'),
            'url' => $branch['url'] ?? $entry?->absoluteUrl(),
            'entry' => $branch['entry'] ?? null,
            'data' => AgentForm::fromStored($fields, $branch['data'] ?? []),
        ];
    }

    /**
     * @param  array<mixed>  $nodes
     * @param  array<string, array<array-key, mixed>>  $stored
     * @return list<array<array-key, mixed>>
     */
    private static function branches(Fields $fields, NavTree $tree, array $nodes, array $stored, string $prefix): array
    {
        $branches = [];

        foreach (array_values($nodes) as $index => $node) {
            $id = is_array($node) && is_string($node['id'] ?? null) ? $node['id'] : null;
            $path = $prefix.($id ?? $index).'.';

            if (! is_array($node)) {
                throw ValidationException::withMessages([rtrim($path, '.') => ['Each node must be an object.']]);
            }

            if ($id !== null && ! isset($stored[$id])) {
                throw ValidationException::withMessages([$path.'id' => ["No node with id [{$id}] in this navigation. Leave the id out for new nodes."]]);
            }

            $children = self::branches($fields, $tree, is_array($node['children'] ?? null) ? $node['children'] : [], $stored, $path.'children.');
            $branch = $id !== null && self::hiddenEntry($stored[$id])
                ? $stored[$id]
                : self::branch($fields, $tree, $node, $id === null ? null : $stored[$id], $path);

            unset($branch['children']);
            $branches[] = $children === [] ? $branch : [...$branch, 'children' => $children];
        }

        return $branches;
    }

    /**
     * One node's stored branch, without children. Attributes the client sent back as
     * get_navigation showed them stay as stored.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<array-key, mixed>|null  $stored
     * @return array<array-key, mixed>
     */
    private static function branch(Fields $fields, NavTree $tree, array $node, ?array $stored, string $path): array
    {
        $shown = $stored === null ? null : self::presentNode($fields, $tree, $stored);
        $unchanged = fn (string $key): bool => $shown !== null && array_key_exists($key, $node) && $node[$key] === $shown[$key];

        $entryId = $unchanged('entry') || ! array_key_exists('entry', $node) ? ($stored['entry'] ?? null) : $node['entry'];
        $entry = null;

        if ($entryId !== null) {
            $entry = is_string($entryId) ? Entries::find($entryId) : null;
            $allowed = $tree->structure()->collections()->map->handle()->all();

            if (! $entry instanceof Entry || Gate::denies('view', $entry)) {
                throw ValidationException::withMessages([$path.'entry' => ['Entry ['.(is_scalar($entryId) ? $entryId : '').'] not found.']]);
            }

            if (! in_array($entry->collectionHandle(), $allowed, true)) {
                throw ValidationException::withMessages([$path.'entry' => ['This navigation links entries of '.($allowed === [] ? 'no collection' : 'these collections only: '.implode(', ', $allowed)).'.']]);
            }
        }

        $title = $unchanged('title') ? ($stored['title'] ?? null) : ($node['title'] ?? null);
        $url = $unchanged('url') ? ($stored['url'] ?? null) : ($node['url'] ?? null);

        if ($entry !== null && $url !== null && ! $unchanged('url')) {
            throw ValidationException::withMessages([$path.'url' => ['A node that links an entry takes its URL from the entry.']]);
        }

        if ($entry === null && ! is_string($title) && ! is_string($url)) {
            throw ValidationException::withMessages([$path.'title' => ['A node without an entry needs a title or a url.']]);
        }

        $data = $unchanged('data') ? ($stored['data'] ?? []) : self::nodeData($fields, $node['data'] ?? [], $stored['data'] ?? [], $path);

        return array_filter([
            'id' => $stored['id'] ?? app(BranchIdGenerator::class)->generate(),
            'entry' => $entry?->id(),
            'title' => $title,
            'url' => $url,
            'data' => $data,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  array<array-key, mixed>  $current
     * @return array<array-key, mixed>
     */
    private static function nodeData(Fields $fields, mixed $data, array $current, string $path): array
    {
        if (! is_array($data)) {
            throw ValidationException::withMessages([$path.'data' => ['Send the node data as an object.']]);
        }

        try {
            return ContentWriter::branchData($fields, $data, $current);
        } catch (ValidationException $e) {
            $errors = [];

            foreach ($e->errors() as $key => $messages) {
                $errors[$path.'data.'.$key] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    private static function nodeFields(NavTree $tree): Fields
    {
        // Title and url are attributes of the branch, not part of its data.
        return $tree->structure()->blueprint()->fields()->except('title', 'url');
    }

    /**
     * @param  array<array-key, mixed>  $branch
     */
    private static function entry(array $branch, NavTree $tree): ?Entry
    {
        $entry = is_string($branch['entry'] ?? null) ? Entries::find($branch['entry']) : null;

        return $entry instanceof Entry ? ($entry->in($tree->locale()) ?? $entry) : null;
    }

    /**
     * @param  array<array-key, mixed>  $branch
     */
    private static function hiddenEntry(array $branch): bool
    {
        $entry = is_string($branch['entry'] ?? null) ? Entries::find($branch['entry']) : null;

        return $entry instanceof Entry && Gate::denies('view', $entry);
    }

    /**
     * @param  list<array<array-key, mixed>>  $branches
     * @return array<string, array<array-key, mixed>>
     */
    private static function byId(array $branches): array
    {
        $byId = [];

        foreach ($branches as $branch) {
            if (is_string($branch['id'] ?? null)) {
                $byId[$branch['id']] = $branch;
            }

            $byId = [...$byId, ...self::byId($branch['children'] ?? [])];
        }

        return $byId;
    }

    /**
     * @param  list<array<array-key, mixed>>  $branches
     */
    private static function depth(array $branches): int
    {
        return array_reduce($branches, fn (int $max, array $branch): int => max($max, 1 + self::depth($branch['children'] ?? [])), 0);
    }

    /**
     * @param  list<array<array-key, mixed>>  $branches
     * @return array<array-key, mixed>|null
     */
    private static function find(array $branches, string $entry): ?array
    {
        foreach ($branches as $branch) {
            if (($branch['entry'] ?? null) === $entry) {
                return $branch;
            }

            if ($found = self::find($branch['children'] ?? [], $entry)) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The level of an entry's page, 1 for the top level.
     *
     * @param  list<array<array-key, mixed>>  $branches
     */
    private static function level(array $branches, string $entry, int $level = 1): ?int
    {
        foreach ($branches as $branch) {
            if (($branch['entry'] ?? null) === $entry) {
                return $level;
            }

            if (($found = self::level($branch['children'] ?? [], $entry, $level + 1)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  list<array<array-key, mixed>>  $branches
     * @return list<array<array-key, mixed>>
     */
    private static function remove(array $branches, string $entry): array
    {
        $kept = [];

        foreach ($branches as $branch) {
            if (($branch['entry'] ?? null) === $entry) {
                continue;
            }

            if (isset($branch['children'])) {
                $branch['children'] = self::remove($branch['children'], $entry);

                if ($branch['children'] === []) {
                    unset($branch['children']);
                }
            }

            $kept[] = $branch;
        }

        return $kept;
    }

    /**
     * Inserts $branch among the children of $parent (the top level when null),
     * before or after a sibling, or at the end.
     *
     * @param  list<array<array-key, mixed>>  $branches
     * @param  array<array-key, mixed>  $branch
     * @return list<array<array-key, mixed>>
     */
    private static function insert(array $branches, array $branch, ?string $parent, ?string $before, ?string $after): array
    {
        if ($parent === null) {
            return self::insertAmong($branches, $branch, $before, $after);
        }

        foreach ($branches as $index => $candidate) {
            if (($candidate['entry'] ?? null) === $parent) {
                $branches[$index]['children'] = self::insertAmong($candidate['children'] ?? [], $branch, $before, $after);
            } elseif (isset($candidate['children'])) {
                $branches[$index]['children'] = self::insert($candidate['children'], $branch, $parent, $before, $after);
            }
        }

        return $branches;
    }

    /**
     * @param  list<array<array-key, mixed>>  $siblings
     * @param  array<array-key, mixed>  $branch
     * @return list<array<array-key, mixed>>
     */
    private static function insertAmong(array $siblings, array $branch, ?string $before, ?string $after): array
    {
        $anchor = $before ?? $after;
        $position = count($siblings);

        foreach ($siblings as $index => $sibling) {
            if ($anchor !== null && ($sibling['entry'] ?? null) === $anchor) {
                $position = $before !== null ? $index : $index + 1;
            }
        }

        array_splice($siblings, $position, 0, [$branch]);

        return $siblings;
    }

    /**
     * Mirrors CollectionTreeController::validateUniqueUris(): a moved page may not
     * end up with the URL of one of its new siblings.
     *
     * @param  list<array<array-key, mixed>>  $branches
     */
    private static function assertUniqueUris(CollectionTree $tree, array $branches): void
    {
        if (! $tree->collection()->route($tree->locale())) {
            return;
        }

        // Flushed: a clone would keep the pages cached from the tree before the move.
        $tree = (clone $tree)->flushCache()->disableUriCache()->tree($branches);

        foreach ($tree->diff()->moved() as $id) {
            $page = $tree->find($id);
            $parent = $page?->parent();

            $siblings = (! $parent || $parent->isRoot())
                ? $tree->pages()->all()->slice(1)
                : $parent->pages()->all();

            $uris = $siblings->reject(fn ($sibling): bool => $sibling->reference() === $id)->map->uri();

            if ($page !== null && $uris->contains($uri = $page->uri())) {
                throw ValidationException::withMessages(['parent' => [trans('statamic::validation.duplicate_uri', ['value' => $uri])]]);
            }
        }
    }
}
