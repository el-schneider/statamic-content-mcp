<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Statamic\Policies\EntryPolicy;

/**
 * How sites scope editors beyond Statamic's permissions: entries with the slug
 * "secret" stay hidden even from editors whose role may view and edit the collection.
 */
final class HideSecretEntriesPolicy extends EntryPolicy
{
    public function view($user, $entry)
    {
        return $entry->slug() !== 'secret' && parent::view($user, $entry);
    }

    public function edit($user, $entry)
    {
        return $entry->slug() !== 'secret' && parent::edit($user, $entry);
    }
}
