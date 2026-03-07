<?php

declare(strict_types=1);

namespace Tests;

use ElSchneider\ContentMcp\ContentMcpServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ContentMcpServiceProvider::class;
}
