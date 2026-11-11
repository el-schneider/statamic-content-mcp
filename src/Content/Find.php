<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Content;

use Illuminate\Support\Facades\Gate;
use Statamic\Entries\Collection;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\GlobalSet as GlobalSets;
use Statamic\Facades\Site as Sites;
use Statamic\Globals\Variables;
use Statamic\Sites\Site;

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
