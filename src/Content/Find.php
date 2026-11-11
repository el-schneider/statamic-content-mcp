<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Content;

use Illuminate\Support\Facades\Gate;
use Statamic\Entries\Collection;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\GlobalSet as GlobalSets;
use Statamic\Facades\Site;
use Statamic\Globals\Variables;

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
     * Global variables are viewable exactly when they are editable.
     *
     * shortcut: globals, find_entries, find_assets, list_terms and create_entry use the default site only
     * (by-id entry tools use the entry's own site); add a `site` argument when multi-site editing is needed.
     */
    public static function globals(string $handle): Variables
    {
        $variables = GlobalSets::findByHandle($handle)?->in(Site::default()->handle());

        if (! $variables instanceof Variables || Gate::denies('edit', $variables)) {
            throw new NotFound("Global set [{$handle}] not found.");
        }

        return $variables;
    }
}
