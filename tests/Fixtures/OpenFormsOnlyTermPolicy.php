<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Statamic\Policies\LocalizedTermPolicy;

/**
 * The term counterpart of OpenFormsOnlyEntryPolicy: `create` and `edit` pass, `store` and `update` do not.
 */
final class OpenFormsOnlyTermPolicy extends LocalizedTermPolicy
{
    public function store($user, $taxonomy, $site = null)
    {
        return false;
    }

    public function update($user, $term)
    {
        return false;
    }
}
