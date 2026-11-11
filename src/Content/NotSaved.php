<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Content;

use RuntimeException;

/**
 * A saving listener on the site returned false, so Statamic did not store the change.
 */
final class NotSaved extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The site cancelled this save (a listener on its saving event rejected it). Nothing was stored.');
    }

    public static function unless(bool $saved): void
    {
        if (! $saved) {
            throw new self;
        }
    }
}
