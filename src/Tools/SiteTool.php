<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Taxonomy;

#[IsReadOnly]
final class SiteTool extends Tool
{
    protected string $name = 'site';

    protected string $description = 'Site overview: collections, taxonomies, globals, asset containers, and navigations.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['overview', 'collections'])
                ->default('overview')
                ->description('Action to perform.'),
        ];
    }

    public function shouldRegister(): bool
    {
        return (bool) config('statamic.content-mcp.tools.site', true);
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'action' => ['sometimes', 'string', 'in:overview,collections'],
        ]);

        return match ($request->get('action', 'overview')) {
            'collections' => Response::json($this->collectionsData()),
            default => Response::json([
                'name' => config('app.name'),
                'collections' => $this->collectionsData(),
                'globals' => GlobalSet::all()->map(fn ($g) => $g->handle())->values()->all(),
                'taxonomies' => $this->taxonomiesData(),
                'asset_containers' => AssetContainer::all()->map(fn ($a) => $a->handle())->values()->all(),
                'navigations' => Nav::all()->map(fn ($n) => $n->handle())->values()->all(),
            ]),
        };
    }

    /** @return array<string, mixed> */
    private function collectionsData(): array
    {
        $allowed = config('statamic.content-mcp.collections');

        return Collection::all()
            ->when($allowed !== null, fn ($c) => $c->filter(fn ($col) => in_array($col->handle(), $allowed)))
            ->mapWithKeys(function ($collection) {
                $data = [
                    'title' => $collection->title(),
                    'blueprints' => $collection->entryBlueprints()->map(fn ($b) => $b->handle())->values()->all(),
                    'route' => $collection->routes()->first(),
                    'entry_count' => $collection->queryEntries()->count(),
                ];

                if ($collection->hasStructure()) {
                    $data['structured'] = true;
                }

                if ($collection->dated()) {
                    $data['dated'] = true;
                }

                $taxonomies = $collection->taxonomies()->map(fn ($t) => $t->handle())->values()->all();
                if ($taxonomies !== []) {
                    $data['taxonomies'] = $taxonomies;
                }

                return [$collection->handle() => $data];
            })
            ->all();
    }

    /** @return array<string, mixed> */
    private function taxonomiesData(): array
    {
        return Taxonomy::all()
            ->mapWithKeys(fn ($taxonomy) => [
                $taxonomy->handle() => [
                    'title' => $taxonomy->title(),
                    'term_count' => $taxonomy->queryTerms()->count(),
                ],
            ])
            ->all();
    }
}
