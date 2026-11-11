<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Settings;
use Illuminate\Support\Collection as Handles;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Entries\Collection;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\Blueprint;
use Statamic\Globals\GlobalSet as GlobalSetModel;
use Statamic\Sites\Site as SiteModel;
use Statamic\Structures\Nav as NavModel;

#[Title('Get site overview')]
#[Description('Start here. Returns the site (on multi-site installs: the sites you may work in, and where each collection and global set exists), the editor you act for, the collections, taxonomies, global sets, navigations and asset containers this editor may access, and the site\'s editorial guidelines. Follow the guidelines in everything you write.')]
#[IsReadOnly]
final class GetOverview extends ContentTool
{
    protected string $name = 'get_overview';

    protected function respond(Request $request): ResponseFactory
    {
        $user = $this->user();
        $site = Site::default();
        $multi = Site::multiEnabled();
        $sites = Site::all()->filter(fn (SiteModel $site): bool => Gate::allows('view', $site));
        // Only the sites the editor may access, so a forbidden site looks like none.
        $accessible = fn (Handles $handles): array => $handles->intersect($sites->keys())->values()->all();

        return Response::structured([
            'site' => ['name' => $site->name(), 'url' => $site->absoluteUrl()],
            ...($multi ? ['sites' => $sites->map(fn (SiteModel $site): array => [
                'handle' => $site->handle(),
                'name' => $site->name(),
                'locale' => $site->locale(),
                'url' => $site->absoluteUrl(),
            ])->values()->all()] : []),
            'user' => ['id' => $user->getAuthIdentifier(), 'name' => $user->name(), 'email' => $user->email()],
            'collections' => Collections::all()
                ->filter(fn (Collection $collection): bool => Gate::allows('view', $collection))
                ->map(fn (Collection $collection): array => [
                    'handle' => $collection->handle(),
                    'title' => $collection->title(),
                    ...($multi ? ['sites' => $accessible($collection->sites())] : []),
                    'blueprints' => $collection->entryBlueprints()->reject(fn (Blueprint $blueprint): bool => (bool) $blueprint->hidden())->map->handle()->values()->all(),
                    'dated' => $collection->dated(),
                    'structured' => $collection->hasStructure(),
                    'can_create' => Gate::allows('create', [EntryContract::class, $collection, $site]),
                ])
                ->values()->all(),
            'taxonomies' => Taxonomy::all()
                ->filter(fn ($taxonomy): bool => Gate::allows('view', $taxonomy))
                ->map(fn ($taxonomy): array => ['handle' => $taxonomy->handle(), 'title' => $taxonomy->title()])
                ->values()->all(),
            'globals' => GlobalSet::all()
                ->map(fn (GlobalSetModel $set): array => [
                    'handle' => $set->handle(),
                    'title' => $set->title(),
                    'sites' => $set->sites()->filter(fn (string $handle): bool => ($variables = $set->in($handle)) !== null && Gate::allows('edit', $variables))->values()->all(),
                ])
                ->filter(fn (array $set): bool => $set['sites'] !== [])
                ->map(fn (array $set): array => $multi ? $set : array_diff_key($set, ['sites' => true]))
                ->values()->all(),
            'navigations' => Nav::all()
                ->filter(fn (NavModel $nav): bool => Gate::allows('view', $nav))
                ->map(fn (NavModel $nav): array => [
                    'handle' => $nav->handle(),
                    'title' => $nav->title(),
                    ...($multi ? ['sites' => $accessible($nav->sites())] : []),
                ])
                ->values()->all(),
            'asset_containers' => AssetContainer::all()
                ->filter(fn ($container): bool => Gate::allows('view', $container))
                ->map(fn ($container): array => ['handle' => $container->handle(), 'title' => $container->title()])
                ->values()->all(),
            'guidelines' => Settings::instructions(),
        ]);
    }
}
