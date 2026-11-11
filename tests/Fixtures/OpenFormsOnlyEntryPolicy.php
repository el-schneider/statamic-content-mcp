<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Statamic\Policies\EntryPolicy;

/**
 * A site that lets editors open the create and edit forms but saves nothing:
 * `create` and `edit` pass, the CP's persisting abilities `store` and `update` do not.
 */
final class OpenFormsOnlyEntryPolicy extends EntryPolicy
{
    public function store($user, $collection, $site = null)
    {
        return false;
    }

    public function update($user, $entry)
    {
        return false;
    }
}
