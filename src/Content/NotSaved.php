<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Content;

use RuntimeException;

/**
 * A saving or deleting listener on the site returned false, so Statamic did not store the change.
 */
final class NotSaved extends RuntimeException
{
    public function __construct(bool $deletion = false)
    {
        parent::__construct($deletion
            ? 'The site cancelled this deletion (a listener on its deleting event rejected it). Nothing was deleted.'
            : 'The site cancelled this save (a listener on its saving event rejected it). Nothing was stored.');
    }

    public static function unless(bool $saved, bool $deletion = false): void
    {
        if (! $saved) {
            throw new self($deletion);
        }
    }
}
