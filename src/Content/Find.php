<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Content;

use Illuminate\Support\Facades\Gate;
use Statamic\Assets\Asset;
use Statamic\Assets\AssetContainer;
use Statamic\Entries\Collection;
use Statamic\Entries\Entry;
use Statamic\Facades\Asset as Assets;
use Statamic\Facades\AssetContainer as AssetContainers;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\GlobalSet as GlobalSets;
use Statamic\Facades\Nav as Navs;
use Statamic\Facades\Site as Sites;
use Statamic\Facades\Taxonomy as Taxonomies;
use Statamic\Facades\Term as Terms;
use Statamic\Globals\GlobalSet;
use Statamic\Globals\Variables;
use Statamic\Sites\Site;
use Statamic\Structures\Nav;
use Statamic\Structures\NavTree;
use Statamic\Taxonomies\LocalizedTerm;
use Statamic\Taxonomies\Taxonomy;

/**
 * Lookups by id or handle that only return what the current user may view.
 */
final class Find
{
    public static function entry(string $id): Entry
    {
        $entry = Entries::find($id);

        if (! $entry instanceof Entry || Gate::denies('view', $entry)) {
            throw new NotFound("Entry [{$id}] not found.");
        }

        return $entry;
    }

    public static function collection(string $handle): Collection
    {
        $collection = Collections::findByHandle($handle);

        if (! $collection || Gate::denies('view', $collection)) {
            throw new NotFound("Collection [{$handle}] not found.");
        }

        return $collection;
    }

    public static function navigation(string $handle): Nav
    {
        $nav = Navs::findByHandle($handle);

        if (! $nav instanceof Nav || Gate::denies('view', $nav)) {
            throw new NotFound("Navigation [{$handle}] not found.");
        }

        return $nav;
    }

    /**
     * A navigation's tree in a site; an empty, unsaved one where it has none yet.
     */
    public static function navigationTree(string $handle, Site $site): NavTree
    {
        $nav = self::navigation($handle);
        $tree = $nav->in($site->handle()) ?? $nav->makeTree($site->handle());

        if (! $tree instanceof NavTree || Gate::denies('view', $tree)) {
            throw new NotFound("Navigation [{$handle}] not found.");
        }

        return $tree;
    }

    public static function asset(string $id): Asset
    {
        $asset = Assets::find($id);

        if (! $asset instanceof Asset || Gate::denies('view', $asset)) {
            throw new NotFound("Asset [{$id}] not found.");
        }

        return $asset;
    }

    public static function assetContainer(string $handle): AssetContainer
    {
        $container = AssetContainers::findByHandle($handle);

        if (! $container instanceof AssetContainer || Gate::denies('view', $container)) {
            throw new NotFound("Asset container [{$handle}] not found.");
        }

        return $container;
    }

    public static function taxonomy(string $handle): Taxonomy
    {
        $taxonomy = Taxonomies::findByHandle($handle);

        if (! $taxonomy instanceof Taxonomy || Gate::denies('view', $taxonomy)) {
            throw new NotFound("Taxonomy [{$handle}] not found.");
        }

        return $taxonomy;
    }

    public static function term(string $taxonomy, string $slug, Site $site): LocalizedTerm
    {
        $found = Terms::find(self::taxonomy($taxonomy)->handle().'::'.$slug);
        $term = $found instanceof LocalizedTerm && $found->taxonomy()->sites()->contains($site->handle()) ? $found->in($site->handle()) : null;

        if (! $term instanceof LocalizedTerm || Gate::denies('view', $term)) {
            throw new NotFound("Term [{$slug}] not found in [{$taxonomy}].");
        }

        return $term;
    }

    /**
     * The given site, or the default site when none is given.
     */
    public static function site(?string $handle): Site
    {
        if ($handle === null) {
            return Sites::default();
        }

        $site = Sites::get($handle);

        if (! $site instanceof Site || Gate::denies('view', $site)) {
            throw new NotFound("Site [{$handle}] not found.");
        }

        return $site;
    }

    /**
     * A global set the user may edit in at least one site.
     */
    public static function globalSet(string $handle): GlobalSet
    {
        $set = GlobalSets::findByHandle($handle);
        $editable = $set instanceof GlobalSet && $set->sites()->contains(fn (string $site): bool => ($variables = $set->in($site)) !== null && Gate::allows('edit', $variables));

        if (! $set instanceof GlobalSet || ! $editable) {
            throw new NotFound("Global set [{$handle}] not found.");
        }

        return $set;
    }

    /**
     * Global variables are viewable exactly when they are editable.
     */
    public static function globals(string $handle, Site $site): Variables
    {
        $variables = GlobalSets::findByHandle($handle)?->in($site->handle());

        if (! $variables instanceof Variables || Gate::denies('edit', $variables)) {
            throw new NotFound("Global set [{$handle}] not found.");
        }

        return $variables;
    }
}
